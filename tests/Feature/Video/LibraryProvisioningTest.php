<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Verified against https://bunny.net/docs/api-reference/core/stream-video-library/add-video-library:
 * POST https://api.bunny.net/videolibrary, header AccessKey: <account key>, body {Name}.
 * Response 201 includes Id, ApiKey (the new library's own key).
 */
function actingOwnerWithLesson(Tenant $tenant, string $domain): array
{
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    return inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });
}

test('the first video upload for a tenant creates its Bunny library exactly once', function () {
    config(['coaching.video_driver' => 'bunny']);
    config(['services.bunny.account_api_key' => 'account-level-key']);

    Http::fake([
        'api.bunny.net/videolibrary' => Http::response(['Id' => 555, 'ApiKey' => 'library-secret-key'], 201),
        'video.bunnycdn.com/library/*/videos' => Http::response(['guid' => 'aaaa-bbbb-cccc'], 200),
    ]);

    $tenant = Tenant::factory()->create();
    [$owner, $lessonOne] = actingOwnerWithLesson($tenant, 'tenant-a.coaching.test');
    $lessonTwo = inTenant($tenant, function () use ($lessonOne) {
        return Lesson::factory()->for($lessonOne->chapter)->create(['course_id' => $lessonOne->course_id]);
    });

    $this->actingAs($owner, 'tenant')
        ->post('http://tenant-a.coaching.test/manage/lessons/'.$lessonOne->id.'/video/start-upload', [
            'filename' => 'lecture-1.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ])->assertOk();

    // Second upload for the same tenant must reuse the already-provisioned library.
    $this->actingAs($owner, 'tenant')
        ->post('http://tenant-a.coaching.test/manage/lessons/'.$lessonTwo->id.'/video/start-upload', [
            'filename' => 'lecture-2.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ])->assertOk();

    Http::assertSentCount(3); // 1 create-library + 2 create-video, never a second create-library.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.bunny.net/videolibrary'
        && $request->hasHeader('AccessKey', 'account-level-key'));
});

test('two different tenants never share a Bunny library, and tenant B never uses tenant A library id or key', function () {
    config(['coaching.video_driver' => 'bunny']);
    config(['services.bunny.account_api_key' => 'account-level-key']);

    Http::fakeSequence('api.bunny.net/videolibrary')
        ->push(['Id' => 111, 'ApiKey' => 'tenant-a-library-key'], 201)
        ->push(['Id' => 222, 'ApiKey' => 'tenant-b-library-key'], 201);
    Http::fake(['video.bunnycdn.com/library/*/videos' => Http::response(['guid' => 'video-guid'], 200)]);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    [$ownerA, $lessonA] = actingOwnerWithLesson($tenantA, 'tenant-a.coaching.test');
    [$ownerB, $lessonB] = actingOwnerWithLesson($tenantB, 'tenant-b.coaching.test');

    $this->actingAs($ownerA, 'tenant')
        ->post('http://tenant-a.coaching.test/manage/lessons/'.$lessonA->id.'/video/start-upload', [
            'filename' => 'lecture.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1_000_000,
        ])->assertOk();

    $this->actingAs($ownerB, 'tenant')
        ->post('http://tenant-b.coaching.test/manage/lessons/'.$lessonB->id.'/video/start-upload', [
            'filename' => 'lecture.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1_000_000,
        ])->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://video.bunnycdn.com/library/111/videos'
        && $request->hasHeader('AccessKey', 'tenant-a-library-key'));
    Http::assertSent(fn ($request) => $request->url() === 'https://video.bunnycdn.com/library/222/videos'
        && $request->hasHeader('AccessKey', 'tenant-b-library-key'));
    Http::assertNotSent(fn ($request) => $request->url() === 'https://video.bunnycdn.com/library/222/videos'
        && $request->hasHeader('AccessKey', 'tenant-a-library-key'));
});

test('library creation failure surfaces a teacher-facing error and leaves no half-created video row', function () {
    config(['coaching.video_driver' => 'bunny']);
    config(['services.bunny.account_api_key' => 'account-level-key']);

    Http::fake(['api.bunny.net/videolibrary' => Http::response(['Message' => 'Account suspended'], 500)]);

    $tenant = Tenant::factory()->create();
    [$owner, $lesson] = actingOwnerWithLesson($tenant, 'tenant-a.coaching.test');

    $response = $this->actingAs($owner, 'tenant')
        ->post('http://tenant-a.coaching.test/manage/lessons/'.$lesson->id.'/video/start-upload', [
            'filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ]);

    $response->assertSessionHasErrors('video');
    expect(session('errors')?->first('video'))->not->toContain('500')
        ->and(session('errors')?->first('video'))->not->toContain('Bunny');

    inTenant($tenant, function () use ($lesson) {
        expect(LessonVideo::where('lesson_id', $lesson->id)->exists())->toBeFalse();
        expect(Tenant::find($lesson->tenant_id)->bunny_library_id)->toBeNull();
    });
});

test('a tenant library API key and token key are stored encrypted, never as plaintext in the database', function () {
    config(['coaching.video_driver' => 'bunny']);
    config(['services.bunny.account_api_key' => 'account-level-key']);

    Http::fake([
        'api.bunny.net/videolibrary' => Http::response(['Id' => 333, 'ApiKey' => 'plaintext-library-secret'], 201),
        'video.bunnycdn.com/library/*/videos' => Http::response(['guid' => 'video-guid'], 200),
    ]);

    $tenant = Tenant::factory()->create();
    [$owner, $lesson] = actingOwnerWithLesson($tenant, 'tenant-a.coaching.test');

    $this->actingAs($owner, 'tenant')
        ->post('http://tenant-a.coaching.test/manage/lessons/'.$lesson->id.'/video/start-upload', [
            'filename' => 'lecture.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1_000_000,
        ])->assertOk();

    $raw = DB::table('tenants')->where('id', $tenant->id)->first();

    // Positive control: the decrypted model attribute equals the value Bunny returned
    expect($tenant->refresh()->bunny_library_api_key)->toBe('plaintext-library-secret');

    // Negative: the raw column value is never the plaintext key
    expect($raw->bunny_library_api_key)->not->toBe('plaintext-library-secret');
    expect($raw->bunny_library_api_key)->not->toContain('plaintext-library-secret');
});

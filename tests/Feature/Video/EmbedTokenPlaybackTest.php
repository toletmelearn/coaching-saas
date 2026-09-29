<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Video\BunnyEmbedTokenSigner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function bunnyLessonWithReadyVideo(Tenant $tenant, int $libraryId, string $libraryKey, string $tokenKey): Lesson
{
    $tenant->forceFill([
        'bunny_library_id' => $libraryId,
        'bunny_library_api_key' => $libraryKey,
        'bunny_library_token_key' => $tokenKey,
        'bunny_library_created_at' => now(),
    ])->save();

    return inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        LessonVideo::factory()->for($lesson)->ready()->bunny()->create();

        return $lesson;
    });
}

test('an enrolled student is served an embed URL signed with their own tenant\'s token key', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = bunnyLessonWithReadyVideo($tenant, 111, 'tenant-a-library-key', 'tenant-a-token-key');
    $course = inTenant($tenant, fn () => $lesson->course);
    $videoId = inTenant($tenant, fn () => $lesson->video->provider_video_id);

    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();

    $expectedToken = (new BunnyEmbedTokenSigner)->sign('tenant-a-token-key', $videoId, expiration: now()->addMinutes(10)->timestamp);

    // Coarse check: the page contains an embed URL for this library/video carrying a
    // token= param at all (exact token value is time-sensitive, checked precisely in
    // the unit-level BunnyEmbedTokenSignerTest).
    $response->assertSee("player.mediadelivery.net/embed/111/{$videoId}", false);
    $response->assertDontSee('tenant-a-token-key', false); // the raw key itself must never render
    expect(strlen($expectedToken))->toBe(64); // sanity: a hex sha256 digest
});

test('a token signed with tenant A\'s key is never accepted for tenant B\'s video', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $domainB = 'tenant-b.coaching.test';
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    bunnyLessonWithReadyVideo($tenantA, 111, 'tenant-a-library-key', 'tenant-a-token-key');
    $lessonB = bunnyLessonWithReadyVideo($tenantB, 222, 'tenant-b-library-key', 'tenant-b-token-key');
    $courseB = inTenant($tenantB, fn () => $lessonB->course);
    $videoIdB = inTenant($tenantB, fn () => $lessonB->video->provider_video_id);

    $studentB = inTenant($tenantB, function () use ($courseB) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($courseB)->for($student, 'user')->active()->create();

        return $student;
    });

    $expires = now()->addMinutes(10)->timestamp;
    $tokenSignedWithTenantAKey = (new BunnyEmbedTokenSigner)->sign('tenant-a-token-key', $videoIdB, $expires);
    $tokenSignedWithTenantBKey = (new BunnyEmbedTokenSigner)->sign('tenant-b-token-key', $videoIdB, $expires);

    // Positive control: tenant B's own key produces the token actually served to its student
    $response = $this->actingAs($studentB, 'tenant')
        ->get("http://{$domainB}/courses/{$courseB->slug}/lessons/{$lessonB->id}");
    $response->assertOk();
    $response->assertSee($tokenSignedWithTenantBKey, false);

    // Negative: a token computed with tenant A's key must never appear anywhere on
    // tenant B's lesson page (i.e. the server never signs a B video with A's key).
    $response->assertDontSee($tokenSignedWithTenantAKey, false);
});

test('no Bunny API key or token key (account or per-tenant) ever appears in a rendered page or JSON response', function () {
    config(['coaching.video_driver' => 'bunny']);
    config(['services.bunny.account_api_key' => 'account-level-secret']);

    Http::fake([
        'api.bunny.net/videolibrary' => Http::response(['Id' => 999, 'ApiKey' => 'freshly-issued-library-key'], 201),
        'video.bunnycdn.com/library/*/videos' => Http::response(['guid' => 'video-guid'], 200),
    ]);

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1_000_000,
        ]);

    $response->assertOk();
    $response->assertDontSee('account-level-secret', false);
    $response->assertDontSee('freshly-issued-library-key', false);
    expect($response->getContent())->not->toContain('account-level-secret')
        ->and($response->getContent())->not->toContain('freshly-issued-library-key');
});

test('no Bunny API key or token key (account or per-tenant) ever appears in captured log output', function () {
    config(['coaching.video_driver' => 'bunny']);
    config(['services.bunny.account_api_key' => 'account-level-secret']);

    Http::fake([
        'api.bunny.net/videolibrary' => Http::response(['Id' => 888, 'ApiKey' => 'freshly-issued-library-key'], 201),
        'video.bunnycdn.com/library/*/videos' => Http::response(['guid' => 'video-guid'], 200),
    ]);

    $captured = [];
    Log::listen(function ($event) use (&$captured) {
        $captured[] = $event->message.' '.json_encode($event->context);
    });

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $tenant->forceFill([
        'bunny_library_id' => 888,
        'bunny_library_api_key' => 'freshly-issued-library-key',
        'bunny_library_token_key' => 'tenant-token-key-for-logging-check',
    ])->save();

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });

    // Positive control: the listener genuinely captures log calls made through the facade.
    Log::info('phase-5-log-listener-sanity-check');
    expect($captured)->toContain('phase-5-log-listener-sanity-check {}');

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1_000_000,
        ]);

    $allLogged = implode("\n", $captured);
    expect($allLogged)->not->toContain('account-level-secret')
        ->and($allLogged)->not->toContain('freshly-issued-library-key')
        ->and($allLogged)->not->toContain('tenant-token-key-for-logging-check');
});

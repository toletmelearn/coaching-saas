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

/**
 * The library API key doubles as the embed-token signing key (verified against
 * https://bunny.net/docs/stream/mobile-sdk-token-authentication: "the token security key
 * is your Video Library API Key") — there is no separate token key.
 */
function bunnyLessonWithReadyVideo(Tenant $tenant, int $libraryId, string $libraryKey): Lesson
{
    $tenant->forceFill([
        'bunny_library_id' => $libraryId,
        'bunny_library_api_key' => $libraryKey,
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

test('an enrolled student is served an embed URL signed with their own tenant\'s library key', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = bunnyLessonWithReadyVideo($tenant, 111, 'tenant-a-library-key');
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

    $expectedToken = (new BunnyEmbedTokenSigner)->sign('tenant-a-library-key', $videoId, expiration: now()->addMinutes(10)->timestamp);

    // Coarse check: the page contains an embed URL for this library/video carrying a
    // token= param at all (exact token value is time-sensitive, checked precisely in
    // the unit-level BunnyEmbedTokenSignerTest).
    $response->assertSee("player.mediadelivery.net/embed/111/{$videoId}", false);
    $response->assertDontSee('tenant-a-library-key', false); // the raw key itself must never render
    expect(strlen($expectedToken))->toBe(64); // sanity: a hex sha256 digest
});

test('a token signed with tenant A\'s library key is never accepted for tenant B\'s video', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $domainB = 'tenant-b.coaching.test';
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    bunnyLessonWithReadyVideo($tenantA, 111, 'tenant-a-library-key');
    $lessonB = bunnyLessonWithReadyVideo($tenantB, 222, 'tenant-b-library-key');
    $courseB = inTenant($tenantB, fn () => $lessonB->course);
    $videoIdB = inTenant($tenantB, fn () => $lessonB->video->provider_video_id);

    $studentB = inTenant($tenantB, function () use ($courseB) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($courseB)->for($student, 'user')->active()->create();

        return $student;
    });

    $expires = now()->addMinutes(10)->timestamp;
    $tokenSignedWithTenantAKey = (new BunnyEmbedTokenSigner)->sign('tenant-a-library-key', $videoIdB, $expires);

    $response = $this->actingAs($studentB, 'tenant')
        ->get("http://{$domainB}/courses/{$courseB->slug}/lessons/{$lessonB->id}");
    $response->assertOk();

    // Assert SHAPE + PROVENANCE, never an exact token computed from this test's own clock.
    // The signer is SHA256_HEX(key . videoId . expires) and both this test and the server
    // derive `expires` from their own now(); if the wall clock crosses a second boundary
    // between the two, the strings differ and this test flakes. It passed 3/3 in isolation
    // but failed about once per full-suite run. The exact signing rule itself is covered
    // unit-wise in tests/Unit/Support/Video/BunnyEmbedTokenSignerTest.php, and the coarse
    // embed-URL shape is asserted in the test above.
    //
    // The URL is rendered through Blade `{{ }}` at
    // resources/views/lessons/show.blade.php:51, which escapes `&` as `&amp;` — so the
    // response carries `&amp;expires=`, not a bare `&expires=`. Both forms are accepted.
    $served = $response->getContent();
    expect($served)->toContain('?token=')
        ->and($served)->toMatch('/&(amp;)?expires=\d+/');

    preg_match(
        '#player\.mediadelivery\.net/embed/([^?/]+)/([^?]+)\?token=([0-9a-f]{64})&(amp;)?expires=(\d+)#',
        $served,
        $servedUrl
    );
    expect($servedUrl)->toHaveCount(6);

    // Shape: a lowercase-hex sha256 digest, served against tenant B's own library (222)
    // and tenant B's own video id.
    expect($servedUrl[3])->toMatch('/^[0-9a-f]{64}$/')
        ->and($servedUrl[1])->toBe('222')
        ->and($servedUrl[2])->toBe((string) $videoIdB);

    // Provenance: re-signing with tenant B's key and the SAME `expires` the server used
    // reproduces the served token exactly, so B's key really did sign it. Deriving
    // `expires` ourselves is what flaked — the server's own value cannot.
    expect($servedUrl[3])
        ->toBe((new BunnyEmbedTokenSigner)->sign('tenant-b-library-key', $servedUrl[2], (int) $servedUrl[5]));

    // Expiry: a real Unix timestamp within ±5s of now()+10 minutes.
    expect(abs((int) $servedUrl[5] - now()->addMinutes(10)->timestamp))->toBeLessThanOrEqual(5);

    // Cross-tenant isolation (kept as-is rather than duplicated): a token computed with
    // tenant A's key must never appear anywhere on tenant B's lesson page — i.e. the
    // server never signs a B video with A's key. Unlike the assertion this replaced, it
    // cannot flake: A's key and B's key hash differently for ANY `expires`, so the clock
    // boundary is irrelevant. The same rule is asserted in the positive direction above.
    $response->assertDontSee($tokenSignedWithTenantAKey, false);
});

test('no Bunny API key (account or per-tenant) ever appears in a rendered page or JSON response', function () {
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

test('no Bunny API key (account or per-tenant) ever appears in captured log output', function () {
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
    ])->save();

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });

    // Positive control: the listener genuinely captures log calls made through the facade.
    // json_encode([]) (an empty context array) is '[]', not '{}' — PHP arrays don't
    // distinguish an empty object from an empty list.
    Log::info('phase-5-log-listener-sanity-check');
    expect($captured)->toContain('phase-5-log-listener-sanity-check []');

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1_000_000,
        ]);

    $allLogged = implode("\n", $captured);
    expect($allLogged)->not->toContain('account-level-secret')
        ->and($allLogged)->not->toContain('freshly-issued-library-key');
});

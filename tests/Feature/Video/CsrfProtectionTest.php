<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;

test('the lesson edit page renders the CSRF meta tag', function () {
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
        ->get("http://{$domain}/manage/lessons/{$lesson->id}/edit");

    $response->assertOk();
    $response->assertSee('<meta name="csrf-token" content="', false);
});

test('the bundled video-upload script sends X-CSRF-TOKEN on our own routes, and none on the external TUS endpoint', function () {
    // The upload flow is bundled via Vite (resources/js/video-upload.js), not inlined
    // into the page, so this asserts on that source module directly rather than on
    // rendered HTML that no longer contains it literally.
    $source = file_get_contents(base_path('resources/js/video-upload.js'));

    // Our own routes (start-upload, fake-upload) carry the token.
    expect($source)->toContain("'X-CSRF-TOKEN': csrfToken")
        ->and($source)->toContain("xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken)");

    // The TUS upload goes straight to Bunny's own server — not one of our routes, no
    // CSRF token involved, only the TUS signature headers the server already computed.
    expect($source)->toContain('endpoint: data.tus_endpoint');
});

test('start-upload requires a valid CSRF token when CSRF verification is enabled', function () {
    // The testing environment's built-in CSRF bypass (PreventRequestForgery::
    // runningUnitTests()) keys off APP_ENV === 'testing'; switching to any other
    // non-production env (here 'local', so the fake driver's own production guard
    // doesn't also trip) makes CSRF actually enforce, exactly as it does in real use.
    app()->instance('env', 'local');

    config(['coaching.video_driver' => 'fake']);

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

    $payload = ['filename' => 'lecture.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1_000_000];
    $url = "http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload";

    // Negative: a token that doesn't match the session's -> 419, never reaching the
    // controller (no lesson_videos row created).
    $this->actingAs($owner, 'tenant')
        ->withSession(['_token' => 'the-real-session-token'])
        ->post($url, $payload, ['X-CSRF-TOKEN' => 'a-completely-different-token'])
        ->assertStatus(419);

    // Positive control: the matching token succeeds.
    $this->actingAs($owner, 'tenant')
        ->withSession(['_token' => 'the-real-session-token'])
        ->post($url, $payload, ['X-CSRF-TOKEN' => 'the-real-session-token'])
        ->assertOk();
});

test('the fake-upload endpoint requires a valid CSRF token when CSRF verification is enabled', function () {
    app()->instance('env', 'local');

    [, $domain, $owner, $videoId] = setUpFakeVideoForUpload();
    $signedUrl = signedFakeUploadUrl($domain, $videoId);

    // Negative: a token that doesn't match the session's -> 419, even though the URL
    // signature itself is perfectly valid.
    $this->actingAs($owner, 'tenant')
        ->withSession(['_token' => 'the-real-session-token'])
        ->post($signedUrl, [
            'file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4'),
        ], ['X-CSRF-TOKEN' => 'wrong-token'])
        ->assertStatus(419);

    // Positive control: the matching token succeeds.
    $this->actingAs($owner, 'tenant')
        ->withSession(['_token' => 'the-real-session-token'])
        ->post($signedUrl, [
            'file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4'),
        ], ['X-CSRF-TOKEN' => 'the-real-session-token'])
        ->assertNoContent();
});

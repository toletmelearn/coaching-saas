<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * URL::temporarySignedRoute() called directly here (not during an actual HTTP request
 * to the tenant's domain) has no request to infer the host from, so it falls back to
 * APP_URL — which would sign against the wrong host and always 403 when the test then
 * requests it from the tenant's own subdomain. Forcing the root URL to that domain
 * first (and resetting it after) matches how the real controller flow works, where
 * Laravel infers the root from the actual incoming request automatically.
 */
function signedStreamUrl(string $domain, int $videoId, DateTimeInterface $expiration): string
{
    URL::forceRootUrl("http://{$domain}");
    $url = URL::temporarySignedRoute('lesson-videos.stream', $expiration, ['lessonVideo' => $videoId]);
    URL::forceRootUrl((string) config('app.url'));

    return $url;
}

function setUpFakeStreamLesson(): array
{
    config(['coaching.video_driver' => 'fake']);
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $lesson, $videoId, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $video = LessonVideo::factory()->for($lesson)->ready()->create();
        Storage::disk('local')->put($video->storage_path, str_repeat('x', 2048));

        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$course, $lesson, $video->id, $student];
    });

    return [$tenant, $domain, $lesson, $videoId, $student];
}

test('a valid signature with valid access returns 200, and a Range request returns 206', function () {
    [, $domain, , $videoId, $student] = setUpFakeStreamLesson();

    $url = signedStreamUrl($domain, $videoId, now()->addMinutes(10));
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}{$path}")
        ->assertOk();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}{$path}", ['Range' => 'bytes=0-99'])
        ->assertStatus(206);
});

test('an expired signature is rejected', function () {
    [, $domain, , $videoId, $student] = setUpFakeStreamLesson();

    $url = signedStreamUrl($domain, $videoId, now()->subMinutes(1));
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}{$path}")
        ->assertForbidden();
});

test('a valid signature but access revoked since render is rejected', function () {
    [$tenant, $domain, $lesson, $videoId, $student] = setUpFakeStreamLesson();

    $url = signedStreamUrl($domain, $videoId, now()->addMinutes(10));
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    // Positive control: works before revocation
    $this->actingAs($student, 'tenant')->get("http://{$domain}{$path}")->assertOk();

    inTenant($tenant, function () use ($lesson, $student) {
        Enrolment::where('course_id', $lesson->course_id)->where('user_id', $student->id)->first()->revoke();
    });

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}{$path}")
        ->assertForbidden();
});

test('the stream response never sets a caching-friendly Cache-Control header', function () {
    [, $domain, , $videoId, $student] = setUpFakeStreamLesson();

    $url = signedStreamUrl($domain, $videoId, now()->addMinutes(10));
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $response = $this->actingAs($student, 'tenant')->get("http://{$domain}{$path}");

    $response->assertOk();
    // Symfony's ResponseHeaderBag always alphabetically sorts Cache-Control directives
    // (ksort in computeCacheControlValue()) — semantically identical to "private, no-store",
    // just never producible in that literal order via the normal directive API.
    $response->assertHeader('Cache-Control', 'no-store, private');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

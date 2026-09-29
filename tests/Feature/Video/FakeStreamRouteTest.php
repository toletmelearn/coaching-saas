<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function setUpFakeStreamLesson(): array
{
    config(['coaching.video_driver' => 'fake']);
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $lesson, $video, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $video = LessonVideo::factory()->for($lesson)->ready()->create();
        Storage::disk('local')->put($video->storage_path ?? "tenants/{$lesson->tenant_id}/videos/{$video->id}.mp4", str_repeat('x', 2048));

        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$course, $lesson, $video, $student];
    });

    return [$tenant, $domain, $lesson, $video, $student];
}

test('a valid signature with valid access returns 200, and a Range request returns 206', function () {
    [, $domain, $lesson, , $student] = setUpFakeStreamLesson();

    $url = URL::temporarySignedRoute('lesson-videos.stream', now()->addMinutes(10), ['lessonVideo' => $lesson->video->id]);
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}{$path}")
        ->assertOk();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}{$path}", ['Range' => 'bytes=0-99'])
        ->assertStatus(206);
});

test('an expired signature is rejected', function () {
    [, $domain, $lesson, , $student] = setUpFakeStreamLesson();

    $url = URL::temporarySignedRoute('lesson-videos.stream', now()->subMinutes(1), ['lessonVideo' => $lesson->video->id]);
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}{$path}")
        ->assertForbidden();
});

test('a valid signature but access revoked since render is rejected', function () {
    [$tenant, $domain, $lesson, , $student] = setUpFakeStreamLesson();

    $url = URL::temporarySignedRoute('lesson-videos.stream', now()->addMinutes(10), ['lessonVideo' => $lesson->video->id]);
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
    [, $domain, $lesson, , $student] = setUpFakeStreamLesson();

    $url = URL::temporarySignedRoute('lesson-videos.stream', now()->addMinutes(10), ['lessonVideo' => $lesson->video->id]);
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $response = $this->actingAs($student, 'tenant')->get("http://{$domain}{$path}");

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'private, no-store');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

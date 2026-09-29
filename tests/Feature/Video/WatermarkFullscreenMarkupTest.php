<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;

function studentViewingReadyVideoLesson(): array
{
    config(['coaching.video_driver' => 'fake']);

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$lesson, $course, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        LessonVideo::factory()->for($lesson)->ready()->create();

        $student = User::factory()->student()->create(['name' => 'Ravi Test', 'phone' => '9123456780']);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$lesson, $course, $student];
    });

    return [$domain, $lesson, $course, $student];
}

test('the watermark overlay markup is present, absolutely positioned and non-interactive', function () {
    [$domain, $lesson, $course, $student] = studentViewingReadyVideoLesson();

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('class="video-watermark"', false);
    $response->assertSee('pointer-events: none', false);
    $response->assertSee('position: absolute', false);
});

test('the watermark script moves the overlay position on an interval between 20 and 40 seconds', function () {
    [$domain, $lesson, $course, $student] = studentViewingReadyVideoLesson();

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    // The randomised-interval scheduling call must be present in the page's inline script.
    $response->assertSee('setTimeout', false);
    expect(preg_match('/Math\.random\(\).*?(20000|20_000).*?(40000|40_000)/s', $response->getContent()))->toBe(1);
});

test('the video wrapper has its own fullscreen control and the iframe/video never carries fullscreen permission', function () {
    [$domain, $lesson, $course, $student] = studentViewingReadyVideoLesson();

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('class="video-fullscreen-button"', false);

    expect($response->getContent())->not->toContain('allowfullscreen')
        ->and($response->getContent())->not->toContain('allow="fullscreen"')
        ->and($response->getContent())->not->toContain("allow='fullscreen'");
});

test('the video iframe (bunny driver) carries referrerpolicy strict-origin-when-cross-origin', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $tenant->forceFill([
        'bunny_library_id' => 111,
        'bunny_library_api_key' => 'library-key',
        'bunny_library_token_key' => 'token-key',
    ])->save();

    [$lesson, $course, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        LessonVideo::factory()->for($lesson)->ready()->bunny()->create();

        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$lesson, $course, $student];
    });

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('referrerpolicy="strict-origin-when-cross-origin"', false);
});

<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Tenant;
use App\Models\User;

function factoryFixture(): array
{
    $tenant = Tenant::factory()->create();

    [$course, $lesson, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $student = User::factory()->student()->create();

        return [$course, $lesson, $student];
    });

    return [$tenant, $course, $lesson, $student];
}

test('the completed() state sets completed_at without completed_manually', function () {
    [$tenant, $course, $lesson, $student] = factoryFixture();

    $progress = inTenant($tenant, fn () => LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->completed()->create());

    expect($progress->completed_at)->not->toBeNull()
        ->and($progress->completed_manually)->toBeFalse();
});

test('the manuallyCompleted() state sets completed_at with completed_manually', function () {
    [$tenant, $course, $lesson, $student] = factoryFixture();

    $progress = inTenant($tenant, fn () => LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->manuallyCompleted()->create());

    expect($progress->completed_at)->not->toBeNull()
        ->and($progress->completed_manually)->toBeTrue();
});

test('the watched() state sets watched_seconds and duration_seconds', function () {
    [$tenant, $course, $lesson, $student] = factoryFixture();

    $progress = inTenant($tenant, fn () => LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->watched(42, 100)->create());

    expect($progress->watched_seconds)->toBe(42)
        ->and($progress->duration_seconds)->toBe(100);
});

test('the atPosition() state sets last_position_seconds and duration_seconds', function () {
    [$tenant, $course, $lesson, $student] = factoryFixture();

    $progress = inTenant($tenant, fn () => LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->atPosition(77, 200)->create());

    expect($progress->last_position_seconds)->toBe(77)
        ->and($progress->duration_seconds)->toBe(200);
});

test('the inactiveSince() state sets last_activity_at that many days in the past', function () {
    [$tenant, $course, $lesson, $student] = factoryFixture();

    $progress = inTenant($tenant, fn () => LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->inactiveSince(10)->create());

    $daysAgo = abs(now()->diffInSeconds($progress->last_activity_at)) / 86400;

    expect($daysAgo)->toBeGreaterThan(9.9)->toBeLessThan(10.1);
});

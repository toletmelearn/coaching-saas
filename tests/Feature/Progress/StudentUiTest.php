<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Tenant;
use App\Models\User;

function studentUiFixture(int $publishedLessons = 4): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $lessons, $student] = inTenant($tenant, function () use ($publishedLessons) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lessons = collect(range(1, $publishedLessons))->map(
            fn () => Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id])
        );
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$course, $lessons, $student];
    });

    return [$tenant, $domain, $course, $lessons, $student];
}

test('the course page shows ticks for completed lessons and a percentage progress bar', function () {
    [$tenant, $domain, $course, $lessons, $student] = studentUiFixture(4);

    inTenant($tenant, function () use ($course, $lessons, $student) {
        foreach ($lessons->take(2) as $lesson) {
            LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->manuallyCompleted()->create();
        }
    });

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}");

    $response->assertOk();
    $response->assertSee('2 of 4', false);
    $response->assertSee('50', false);
});

test('a viewer without a valid enrolment does not see the progress bar', function () {
    [$tenant, $domain, $course, , $student] = studentUiFixture(4);
    $other = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control: the actually-enrolled student does see the progress summary.
    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertSee('of 4', false);

    $response = $this->actingAs($other, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}");

    $response->assertDontSee('of 4', false);
});

test('draft lessons are excluded from the course progress denominator', function () {
    [$tenant, $domain, $course, $lessons, $student] = studentUiFixture(2);
    inTenant($tenant, function () use ($course) {
        $chapter = $course->chapters()->first();
        Lesson::factory()->for($chapter)->create(['course_id' => $course->id]); // draft
    });

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}");

    $response->assertSee('0 of 2', false);
});

test('publishing a new lesson lowers the course completion percentage', function () {
    [$tenant, $domain, $course, $lessons, $student] = studentUiFixture(2);

    inTenant($tenant, fn () => LessonProgress::factory()->for($lessons->first())->for($course)->for($student, 'user')->manuallyCompleted()->create());

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertSee('1 of 2', false);

    inTenant($tenant, function () use ($course) {
        $chapter = $course->chapters()->first();
        Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
    });

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertSee('1 of 3', false);
});

test('the student dashboard shows a progress bar per course and Continue targets the first not-completed lesson', function () {
    [$tenant, $domain, $course, $lessons, $student] = studentUiFixture(3);

    inTenant($tenant, fn () => LessonProgress::factory()->for($lessons->get(0))->for($course)->for($student, 'user')->manuallyCompleted()->create());

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertSee("/courses/{$course->slug}/lessons/{$lessons->get(1)->id}", false);
});

test('the dashboard falls back to the course page with a completion message when every lesson is done', function () {
    [$tenant, $domain, $course, $lessons, $student] = studentUiFixture(1);

    inTenant($tenant, fn () => LessonProgress::factory()->for($lessons->first())->for($course)->for($student, 'user')->manuallyCompleted()->create());

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertSee("/courses/{$course->slug}\"", false);
    $response->assertSee('Course complete', false);
});

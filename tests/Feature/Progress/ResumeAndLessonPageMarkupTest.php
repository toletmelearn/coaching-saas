<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;

function lessonPageFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $lesson, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        LessonVideo::factory()->for($lesson)->ready()->create(['duration_seconds' => 600]);
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$course, $lesson, $student];
    });

    return [$tenant, $domain, $course, $lesson, $student];
}

// === Resume ===

test('the lesson page resumes from the stored position when within the 10s-95% window', function () {
    [$tenant, $domain, $course, $lesson, $student] = lessonPageFixture();

    inTenant($tenant, fn () => LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->atPosition(300, 600)->create());

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('data-start-position="300"', false);
});

test('the lesson page starts from zero when the stored position is below 10 seconds', function () {
    [$tenant, $domain, $course, $lesson, $student] = lessonPageFixture();

    inTenant($tenant, fn () => LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->atPosition(5, 600)->create());

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertSee('data-start-position="0"', false);
});

test('the lesson page starts from zero when the stored position is at or beyond 95% of duration', function () {
    [$tenant, $domain, $course, $lesson, $student] = lessonPageFixture();

    inTenant($tenant, fn () => LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->atPosition(590, 600)->create());

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertSee('data-start-position="0"', false);
});

// === Lesson page markup / data attributes ===

test('an enrolled student sees the progress data attributes, the bundled script, and no third-party scripts', function () {
    [, $domain, $course, $lesson, $student] = lessonPageFixture();

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    // Positive control: the wrapper must actually carry the progress data attributes
    // for a viewer who is allowed to be recorded — proves the negative assertions
    // below (owner/guest never seeing them) are real, not a vacuous "nothing exists".
    $response->assertSee('data-progress-url', false);
    $response->assertSee('data-driver', false);
    $response->assertDontSee('youtube.com/iframe_api', false);
    $response->assertDontSee('googleapis.com', false);
});

test('owner never sees the progress data attributes (preview records nothing)', function () {
    [$tenant, $domain, $course, $lesson, $student] = lessonPageFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Positive control: the same lesson page does carry the attributes for a real,
    // recordable student — so the owner assertion below is a genuine negative.
    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertSee('data-progress-url', false);

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertDontSee('data-progress-url', false);
});

test('a guest never sees the progress data attributes', function () {
    [$tenant, $domain, $course, $lesson, $student] = lessonPageFixture();
    inTenant($tenant, function () use ($lesson) {
        $lesson->forceFill(['is_free_preview' => true])->save();
    });

    // Positive control: the same lesson page does carry the attributes for a real,
    // recordable student.
    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertSee('data-progress-url', false);

    // actingAs() sets the guard's resolved user for the rest of this test method, not
    // just the next request — without resetting it here, this "guest" request would
    // still silently be authenticated as $student (see Tests\Pest.php's
    // freshRequestCycle() doc comment).
    freshRequestCycle();

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertDontSee('data-progress-url', false);
});

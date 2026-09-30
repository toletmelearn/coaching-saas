<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

function completionFixture(array $lessonOverrides = []): array
{
    $tenant = Tenant::factory()->create();
    $domain = Str::random(8).'.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$lesson, $student] = inTenant($tenant, function () use ($lessonOverrides) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(array_merge(['course_id' => $course->id], $lessonOverrides));
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$lesson, $student];
    });

    return [$tenant, $domain, $lesson, $student];
}

test('a student can mark a notes-only lesson as complete', function () {
    [$tenant, $domain, $lesson, $student] = completionFixture();

    $this->actingAs($student, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => true])
        ->assertOk();

    inTenant($tenant, function () use ($lesson, $student) {
        $progress = LessonProgress::where('lesson_id', $lesson->id)->where('user_id', $student->id)->first();
        expect($progress->completed_at)->not->toBeNull()
            ->and($progress->completed_manually)->toBeTrue();
    });
});

test('a student can unmark a manual completion', function () {
    [$tenant, $domain, $lesson, $student] = completionFixture();

    $this->actingAs($student, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => true])
        ->assertOk();

    $this->actingAs($student, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => false])
        ->assertOk();

    inTenant($tenant, function () use ($lesson, $student) {
        $progress = LessonProgress::where('lesson_id', $lesson->id)->where('user_id', $student->id)->first();
        expect($progress->completed_at)->toBeNull();
    });
});

test('a YouTube free-preview lesson can be marked complete manually', function () {
    [$tenant, $domain, $lesson, $student] = completionFixture([
        'is_free_preview' => true,
        'youtube_video_id' => 'dQw4w9WgXcQ',
    ]);

    $this->actingAs($student, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => true])
        ->assertOk();
});

test('manual completion is refused for a lesson with a ready protected video', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$lesson, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        LessonVideo::factory()->for($lesson)->ready()->create();
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$lesson, $student];
    });

    $this->actingAs($student, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => true])
        ->assertUnprocessable();
});

test('an auto-completed video lesson cannot be unmarked via the manual endpoint', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$lesson, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        LessonVideo::factory()->for($lesson)->ready()->create();
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
        LessonProgress::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        return [$lesson, $student];
    });

    $this->actingAs($student, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => false])
        ->assertUnprocessable();
});

test('a non-enrolled student is forbidden from marking completion', function () {
    [$tenant, $domain, $lesson] = completionFixture();
    $other = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($other, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => true])
        ->assertForbidden();
});

test('a guest gets a 401 for a JSON completion request', function () {
    [, $domain, $lesson] = completionFixture();

    $this->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => true])
        ->assertUnauthorized();
});

test('owner/staff get a 204 and nothing is stored on completion', function () {
    [$tenant, $domain, $lesson] = completionFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => true])
        ->assertNoContent();

    inTenant($tenant, fn () => expect(LessonProgress::count())->toBe(0));
});

test('another tenant\'s lesson 404s for the completion endpoint', function () {
    [, $domainA, $lessonA, $studentA] = completionFixture();
    [, , $lessonB] = completionFixture();

    // Positive control: the student can mark their own tenant's lesson complete.
    $this->actingAs($studentA, 'tenant')
        ->putJson("http://{$domainA}/lessons/{$lessonA->id}/completion", ['completed' => true])
        ->assertOk();

    $this->actingAs($studentA, 'tenant')
        ->putJson("http://{$domainA}/lessons/{$lessonB->id}/completion", ['completed' => true])
        ->assertNotFound();
});

test('the completed field is required and must be boolean', function () {
    [$tenant, $domain, $lesson, $student] = completionFixture();

    $this->actingAs($student, 'tenant')
        ->putJson("http://{$domain}/lessons/{$lesson->id}/completion", ['completed' => 'yes'])
        ->assertUnprocessable();
});

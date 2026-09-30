<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Tenant;
use App\Models\User;

function accountingFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$lesson, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$lesson, $student];
    });

    return [$tenant, $domain, $lesson, $student];
}

test('normal sequential playback accumulates watched_seconds across heartbeats', function () {
    [$tenant, $domain, $lesson, $student] = accountingFixture();

    $this->travelTo(now());
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 15, 'duration' => 300, 'played' => 15])
        ->assertOk()
        ->assertJson(['watched_seconds' => 15]);

    $this->travel(15)->seconds();
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 30, 'duration' => 300, 'played' => 15])
        ->assertOk()
        ->assertJson(['watched_seconds' => 30]);
});

test('duplicate/out-of-order heartbeats never reduce watched_seconds or un-complete a lesson', function () {
    [$tenant, $domain, $lesson, $student] = accountingFixture();

    $this->travelTo(now());
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($student, 'tenant')
            ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 17, 'duration' => 100, 'played' => 17]);
        $this->travel(17)->seconds();
    }

    $watchedBefore = inTenant($tenant, fn () => LessonProgress::where('lesson_id', $lesson->id)->first()->watched_seconds);
    expect($watchedBefore)->toBeGreaterThanOrEqual(85);

    // Stale/out-of-order heartbeat reporting a small position and no new playback.
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 5, 'duration' => 100, 'played' => 0])
        ->assertOk();

    inTenant($tenant, function () use ($lesson, $watchedBefore) {
        $progress = LessonProgress::where('lesson_id', $lesson->id)->first();
        expect($progress->watched_seconds)->toBeGreaterThanOrEqual($watchedBefore)
            ->and($progress->completed_at)->not->toBeNull();
    });
});

test('watching 85 percent of the duration completes the lesson without a manual mark', function () {
    [$tenant, $domain, $lesson, $student] = accountingFixture();

    $this->travelTo(now());
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($student, 'tenant')
            ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 17, 'duration' => 100, 'played' => 17]);
        $this->travel(17)->seconds();
    }

    $response = $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 100, 'duration' => 100, 'played' => 2, 'ended' => true]);

    $response->assertJson(['completed' => true]);
    inTenant($tenant, function () use ($lesson, $student) {
        $progress = LessonProgress::where('lesson_id', $lesson->id)->where('user_id', $student->id)->first();
        expect($progress->completed_manually)->toBeFalse();
    });
});

test('a conflicting later duration is ignored once the duration is established', function () {
    [$tenant, $domain, $lesson, $student] = accountingFixture();

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 5, 'duration' => 300, 'played' => 5])
        ->assertOk();

    $this->travel(5)->seconds();
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 10, 'duration' => 30, 'played' => 5])
        ->assertOk();

    inTenant($tenant, function () use ($lesson) {
        $progress = LessonProgress::where('lesson_id', $lesson->id)->first();
        expect($progress->duration_seconds)->toBe(300);
    });
});

test('two interleaved tabs updating the same lesson never lose progress', function () {
    [$tenant, $domain, $lesson, $student] = accountingFixture();

    $this->travelTo(now());

    // Tab 1 heartbeat
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 10, 'duration' => 200, 'played' => 10])
        ->assertOk();

    // Tab 2 heartbeat interleaved shortly after, reporting playback from a slightly
    // different position (both tabs playing the same lesson concurrently).
    $this->travel(3)->seconds();
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 12, 'duration' => 200, 'played' => 3])
        ->assertOk();

    // Tab 1 heartbeat again.
    $this->travel(7)->seconds();
    $response = $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 20, 'duration' => 200, 'played' => 7]);

    $response->assertOk();
    inTenant($tenant, function () use ($lesson) {
        $progress = LessonProgress::where('lesson_id', $lesson->id)->first();
        // 10 + 3 + 7 = 20 seconds of real accepted playback total, none lost.
        expect($progress->watched_seconds)->toBe(20);
    });
});

test('last_activity_at reflects the most recent accepted heartbeat time', function () {
    [$tenant, $domain, $lesson, $student] = accountingFixture();

    $this->travelTo($start = now());
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 5, 'duration' => 100, 'played' => 5])
        ->assertOk();

    $this->travel(30)->seconds();
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 10, 'duration' => 100, 'played' => 5])
        ->assertOk();

    inTenant($tenant, function () use ($lesson, $start) {
        $progress = LessonProgress::where('lesson_id', $lesson->id)->first();
        expect($progress->last_activity_at->gt($start))->toBeTrue();
    });
});

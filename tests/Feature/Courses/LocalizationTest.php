<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

test('course catalogue page renders all text via translation keys', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => Course::factory()->published()->create(['title' => 'Physics - Class 11']));

    $response = $this->get("http://{$domain}/courses");

    $response->assertOk();
    $content = $response->getContent();

    expect($content)->toContain(__('courses.index.heading'));
    expect($content)->not->toContain('__(');
});

test('lesson page renders all text via translation keys', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    });
    $course = inTenant($tenant, fn () => $lesson->course);

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    expect($response->getContent())->not->toContain('__(');
});

test('enrolments management page renders all text via translation keys', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();

        return [$owner, $course];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments");

    $response->assertOk();
    expect($response->getContent())->not->toContain('__(');
});

test('paid lesson without a video shows "video coming soon"', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]);
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $lesson;
    });
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->first()->user);

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee(__('lessons.video_coming_soon'));
});

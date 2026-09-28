<?php

use App\Enums\CourseStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

test('owner can create, publish and archive a course; staff cannot', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control: owner can create a course
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses", [
            'title' => 'Physics - Class 11',
            'slug' => 'physics-class-11',
        ])->assertRedirect();

    $course = inTenant($tenant, fn () => Course::where('slug', 'physics-class-11')->firstOrFail());

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/publish")
        ->assertRedirect();

    inTenant($tenant, fn () => $course->refresh());
    expect($course->status)->toBe(CourseStatus::Published);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/archive")
        ->assertRedirect();

    inTenant($tenant, fn () => $course->refresh());
    expect($course->status)->toBe(CourseStatus::Archived);

    // Negative: staff cannot create, publish or archive a course
    $createResponse = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/courses", [
            'title' => 'Chemistry - Class 11',
            'slug' => 'chemistry-class-11',
        ]);
    $createResponse->assertForbidden();
    expect(inTenant($tenant, fn () => Course::where('slug', 'chemistry-class-11')->first()))->toBeNull();

    $draftCourse = inTenant($tenant, fn () => Course::factory()->draft()->create());

    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/courses/{$draftCourse->id}/publish")
        ->assertForbidden();

    $publishedCourse = inTenant($tenant, fn () => Course::factory()->published()->create());

    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/courses/{$publishedCourse->id}/archive")
        ->assertForbidden();
});

test('staff can add and publish lessons', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$staff, $chapter] = inTenant($tenant, function () {
        $staff = User::factory()->staff()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return [$staff, $chapter];
    });

    $response = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/chapters/{$chapter->id}/lessons", [
            'title' => 'Introduction',
        ]);

    $response->assertRedirect();

    $lesson = inTenant($tenant, fn () => Lesson::where('title', 'Introduction')->firstOrFail());

    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/publish")
        ->assertRedirect();
});

test('student cannot reach any management route', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$staff, $student, $chapter] = inTenant($tenant, function () {
        $staff = User::factory()->staff()->create();
        $student = User::factory()->student()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return [$staff, $student, $chapter];
    });

    // Positive control: staff CAN reach the management course list
    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/manage/courses")
        ->assertOk();

    freshRequestCycle();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/manage/courses")
        ->assertForbidden();

    freshRequestCycle();

    $this->actingAs($student, 'tenant')
        ->post("http://{$domain}/manage/chapters/{$chapter->id}/lessons", [
            'title' => 'Hacked lesson',
        ])->assertForbidden();
});

test('staff cannot manage enrolments; owner can', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $staff, $course, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();

        return [$owner, $staff, $course, $student];
    });

    // Positive control: owner can view the enrolments screen and enrol a student
    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments")
        ->assertOk();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => now()->toDateString(),
        ])->assertRedirect();

    // Negative: staff cannot view or act on the enrolments screen
    $anotherStudent = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments")
        ->assertForbidden();

    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$anotherStudent->id],
            'starts_at' => now()->toDateString(),
        ])->assertForbidden();
});

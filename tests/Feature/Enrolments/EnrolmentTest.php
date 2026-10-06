<?php

use App\Enums\EnrolmentStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;

test('owner can enrol multiple students in one request; already-enrolled students are skipped and reported', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $studentA, $studentB, $alreadyEnrolled] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create(['enrolment_duration' => 'lifetime']);
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();
        $alreadyEnrolled = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($alreadyEnrolled, 'user')->active()->create();

        return [$owner, $course, $studentA, $studentB, $alreadyEnrolled];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$studentA->id, $studentB->id, $alreadyEnrolled->id],
            'starts_at' => now()->toDateString(),
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('enrolment_summary');

    $enrolments = inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->count());
    expect($enrolments)->toBe(3);
});

test('only active students can be enrolled; staff, owner and disabled ids are rejected', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $validStudent, $staff, $otherOwner, $disabledStudent] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create(['enrolment_duration' => 'lifetime']);
        $validStudent = User::factory()->student()->create();
        $staff = User::factory()->staff()->create();
        $otherOwner = User::factory()->owner()->create();
        $disabledStudent = User::factory()->student()->disabled()->create();

        return [$owner, $course, $validStudent, $staff, $otherOwner, $disabledStudent];
    });

    // Positive control: enrolling a valid active student succeeds
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$validStudent->id],
            'starts_at' => now()->toDateString(),
        ])->assertRedirect();

    foreach (['staff' => $staff, 'owner' => $otherOwner, 'disabled student' => $disabledStudent] as $label => $ineligible) {
        $response = $this->actingAs($owner, 'tenant')
            ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
                'user_ids' => [$ineligible->id],
                'starts_at' => now()->toDateString(),
            ]);

        $response->assertSessionHasErrors('user_ids');
        expect(inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->where('user_id', $ineligible->id)->exists()))
            ->toBeFalse();
    }
});

test('revoke keeps the row and sets revoked_at/by; re-enrol reactivates the same row', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $enrolment] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();
        $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$owner, $course, $enrolment];
    });

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/enrolments/{$enrolment->id}/revoke")
        ->assertRedirect();

    inTenant($tenant, fn () => $enrolment->refresh());
    expect($enrolment->status)->toBe(EnrolmentStatus::Revoked)
        ->and($enrolment->revoked_at)->not->toBeNull()
        ->and($enrolment->revoked_by)->toBe($owner->id);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/enrolments/{$enrolment->id}/reenrol")
        ->assertRedirect();

    inTenant($tenant, fn () => $enrolment->refresh());
    expect($enrolment->status)->toBe(EnrolmentStatus::Active);

    $rowCount = inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->count());
    expect($rowCount)->toBe(1);
});

test('cannot enrol into a draft or archived course', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $publishedCourse, $draftCourse, $archivedCourse, $student1, $student2, $student3] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $publishedCourse = Course::factory()->published()->create(['enrolment_duration' => 'lifetime']);
        $draftCourse = Course::factory()->draft()->create();
        $archivedCourse = Course::factory()->archived()->create();
        $student1 = User::factory()->student()->create();
        $student2 = User::factory()->student()->create();
        $student3 = User::factory()->student()->create();

        return [$owner, $publishedCourse, $draftCourse, $archivedCourse, $student1, $student2, $student3];
    });

    // Positive control: enrolling into a published course succeeds
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$publishedCourse->id}/enrolments", [
            'user_ids' => [$student1->id],
            'starts_at' => now()->toDateString(),
        ])->assertRedirect();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$draftCourse->id}/enrolments", [
            'user_ids' => [$student2->id],
            'starts_at' => now()->toDateString(),
        ])->assertForbidden();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$archivedCourse->id}/enrolments", [
            'user_ids' => [$student3->id],
            'starts_at' => now()->toDateString(),
        ])->assertForbidden();
});

test('ends_at before starts_at is rejected', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student1, $student2] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student1 = User::factory()->student()->create();
        $student2 = User::factory()->student()->create();

        return [$owner, $course, $student1, $student2];
    });

    // Positive control: ends_at after starts_at succeeds
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student1->id],
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student2->id],
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
        ]);

    $response->assertSessionHasErrors('ends_at');
});

<?php

use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Batch 3.2 — Enrolment renewal / extension.
 *
 * All 6 tests fail because the route
 * PATCH /manage/courses/{course}/enrolments/{enrolment}/extend
 * does not exist yet — every call returns 404.
 *
 * Tests that expect 403 (revoked enrolment, student access) fail because the
 * actual response is 404 (route not found), not 403 (policy denial).
 */

test('3.2 owner can extend an active enrolment by duration (1_month adds 1 month)', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3renew.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student, $enrolment] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();
        $ends = Carbon::now()->addMonths(2);
        $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'ends_at' => $ends,
        ]);

        return [$owner, $course, $student, $enrolment];
    });

    $expectedEndsAt = $enrolment->ends_at->addMonth();

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/courses/{$course->id}/enrolments/{$enrolment->id}/extend", [
            'duration' => '1_month',
        ])
        ->assertRedirect();

    expect($enrolment->fresh()->ends_at->toDateString())->toBe($expectedEndsAt->toDateString());
});

test('3.2 owner can extend an enrolment by explicit new_ends_at date', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3renew.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student, $enrolment] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();
        $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'ends_at' => Carbon::now()->addMonth(),
        ]);

        return [$owner, $course, $student, $enrolment];
    });

    $newEndsAt = '2027-12-31';
    $expectedEndsAt = Carbon::parse($newEndsAt, 'Asia/Kolkata')->endOfDay()->utc();

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/courses/{$course->id}/enrolments/{$enrolment->id}/extend", [
            'new_ends_at' => $newEndsAt,
        ])
        ->assertRedirect();

    expect($enrolment->fresh()->ends_at->utc()->toDateTimeString())->toBe($expectedEndsAt->toDateTimeString());
});

test('3.2 extending a lifetime enrolment (null ends_at) with 1_month sets ends_at to now + 1 month', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3renew.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student, $enrolment] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();
        $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'ends_at' => null, // lifetime / no expiry
        ]);

        return [$owner, $course, $student, $enrolment];
    });

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/courses/{$course->id}/enrolments/{$enrolment->id}/extend", [
            'duration' => '1_month',
        ])
        ->assertRedirect();

    $updatedEnrolment = $enrolment->fresh();

    expect($updatedEnrolment->ends_at)->not->toBeNull();
    expect($updatedEnrolment->ends_at->toDateString())->toBe(now()->addMonth()->toDateString());
});

test('3.2 extending a revoked enrolment returns 403', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3renew.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student, $enrolment] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();
        $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->revoked()->create();

        return [$owner, $course, $student, $enrolment];
    });

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/courses/{$course->id}/enrolments/{$enrolment->id}/extend", [
            'duration' => '1_month',
        ])
        ->assertForbidden();
});

test('3.2 a student cannot call the extend route (403)', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3renew.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $student, $enrolment] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();
        $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$course, $student, $enrolment];
    });

    $this->actingAs($student, 'tenant')
        ->patch("http://{$domain}/manage/courses/{$course->id}/enrolments/{$enrolment->id}/extend", [
            'duration' => '1_month',
        ])
        ->assertForbidden();
});

test('3.2 extension is logged to admin_audit_logs with action enrolment_extended', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3renew.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student, $enrolment] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();
        $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'ends_at' => Carbon::now()->addMonth(),
        ]);

        return [$owner, $course, $student, $enrolment];
    });

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/courses/{$course->id}/enrolments/{$enrolment->id}/extend", [
            'duration' => '1_month',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'enrolment_extended',
        'target_type' => 'Enrolment',
        'target_id' => $enrolment->id,
    ]);
});

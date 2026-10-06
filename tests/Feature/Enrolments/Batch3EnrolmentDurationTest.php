<?php

use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Batch 3.1 — Course default enrolment duration.
 *
 * Tests 1–2 fail because courses.enrolment_duration column does not exist yet
 * (SQLSTATE HY000: no column named enrolment_duration).
 * Tests 3–6, 8 fail at the same SQL error when setting the course default.
 * Test 7 fails because the store() method currently accepts a null ends_at
 * even when the course has no duration default — assertSessionHasErrors('ends_at')
 * returns false on current code.
 */

test('3.1 course saves enrolment_duration lifetime', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'b3dur.coaching.test', 'type' => 'subdomain']);

    $course = inTenant($tenant, fn () => Course::factory()->create(['enrolment_duration' => 'lifetime']));

    expect($course->fresh()->enrolment_duration)->toBe('lifetime');
});

test('3.1 course saves enrolment_duration null (no default)', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'b3dur.coaching.test', 'type' => 'subdomain']);

    $course = inTenant($tenant, function () {
        $course = Course::factory()->create();
        $course->forceFill(['enrolment_duration' => null])->save();

        return $course;
    });

    expect($course->fresh()->enrolment_duration)->toBeNull();
});

test('3.1 enrolment duration 1_month: ends_at is starts_at + 1 month end of day IST', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3dur.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $course->forceFill(['enrolment_duration' => '1_month'])->save();
        $student = User::factory()->student()->create();

        return [$owner, $course, $student];
    });

    $startsAt = '2026-01-15 00:00:00';
    $expectedEndsAt = Carbon::parse($startsAt, 'Asia/Kolkata')->addMonth()->endOfDay()->utc();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => $startsAt,
        ])
        ->assertRedirect();

    $enrolment = inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first());

    expect($enrolment->ends_at->utc()->toDateTimeString())->toBe($expectedEndsAt->toDateTimeString());
});

test('3.1 enrolment duration 3_months: ends_at is starts_at + 3 months end of day IST', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3dur.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $course->forceFill(['enrolment_duration' => '3_months'])->save();
        $student = User::factory()->student()->create();

        return [$owner, $course, $student];
    });

    $startsAt = '2026-01-15 00:00:00';
    $expectedEndsAt = Carbon::parse($startsAt, 'Asia/Kolkata')->addMonths(3)->endOfDay()->utc();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => $startsAt,
        ])
        ->assertRedirect();

    $enrolment = inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first());

    expect($enrolment->ends_at->utc()->toDateTimeString())->toBe($expectedEndsAt->toDateTimeString());
});

test('3.1 enrolment duration session: ends_at is starts_at + 6 months end of day IST', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3dur.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $course->forceFill(['enrolment_duration' => 'session'])->save();
        $student = User::factory()->student()->create();

        return [$owner, $course, $student];
    });

    $startsAt = '2026-01-15 00:00:00';
    $expectedEndsAt = Carbon::parse($startsAt, 'Asia/Kolkata')->addMonths(6)->endOfDay()->utc();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => $startsAt,
        ])
        ->assertRedirect();

    $enrolment = inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first());

    expect($enrolment->ends_at->utc()->toDateTimeString())->toBe($expectedEndsAt->toDateTimeString());
});

test('3.1 enrolment duration lifetime: ends_at is null (no expiry)', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3dur.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $course->forceFill(['enrolment_duration' => 'lifetime'])->save();
        $student = User::factory()->student()->create();

        return [$owner, $course, $student];
    });

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => '2026-01-15 00:00:00',
        ])
        ->assertRedirect();

    $enrolment = inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first());

    expect($enrolment->ends_at)->toBeNull();
});

test('3.1 validation fails when course has no duration default and ends_at is not supplied', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3dur.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create(); // enrolment_duration = null (no default)
        $student = User::factory()->student()->create();

        return [$owner, $course, $student];
    });

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => now()->format('Y-m-d H:i:s'),
            // no ends_at supplied
        ])
        ->assertSessionHasErrors('ends_at');
});

test('3.1 explicit ends_at always overrides the course duration default', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3dur.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $course->forceFill(['enrolment_duration' => '1_month'])->save();
        $student = User::factory()->student()->create();

        return [$owner, $course, $student];
    });

    $explicitEndsAt = '2026-06-30';
    $expectedEndsAt = Carbon::parse($explicitEndsAt, 'Asia/Kolkata')->endOfDay()->utc();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => '2026-01-15 00:00:00',
            'ends_at' => $explicitEndsAt,
        ])
        ->assertRedirect();

    $enrolment = inTenant($tenant, fn () => Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first());

    expect($enrolment->ends_at->utc()->toDateTimeString())->toBe($expectedEndsAt->toDateTimeString());
});

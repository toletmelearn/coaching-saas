<?php

use App\Models\Course;
use App\Models\Tenant;
use App\Models\User;

/**
 * Batch 3.3 — Fee input in rupees (teacher-facing).
 *
 * Tests 1–3 fail because the current controller reads fee_paise from the request
 * (not fee), so posting a fee field does not produce the expected fee_paise value.
 * Tests 1–2: posting fee=X with a conflicting fee_paise value proves the controller
 * is not reading the new field (fee_paise wins on old code).
 * Test 3: posting fee='' with fee_paise=49900 — old code stores 49900, not null.
 * Tests 4–5 fail because there is no validation rule for the fee field yet.
 * Test 6 fails because the form still uses name="fee_paise", not name="fee".
 */

test('3.3 teacher posts fee in rupees and course stores it as paise', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3fee.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Post fee=499 (rupees) with a conflicting fee_paise=0.
    // New code: reads fee → fee_paise = 49900 (ignores fee_paise field).
    // Old code: reads fee_paise=0 → fee_paise = 0.
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses", [
            'title' => 'Fee Test Course',
            'fee' => 499,
            'fee_paise' => 0, // old field — should be ignored in new code
        ])
        ->assertRedirect();

    $course = inTenant($tenant, fn () => Course::where('title', 'Fee Test Course')->firstOrFail());

    expect($course->fee_paise)->toBe(49900);
});

test('3.3 teacher posts fee 0 and course stores fee_paise 0', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3fee.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Post fee=0 with a conflicting fee_paise=49900.
    // New code: fee=0 → fee_paise = 0.
    // Old code: fee_paise=49900 → fee_paise = 49900.
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses", [
            'title' => 'Zero Fee Course',
            'fee' => 0,
            'fee_paise' => 49900,
        ])
        ->assertRedirect();

    $course = inTenant($tenant, fn () => Course::where('title', 'Zero Fee Course')->firstOrFail());

    expect($course->fee_paise)->toBe(0);
});

test('3.3 teacher posts blank fee and course stores null fee_paise', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3fee.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Post fee='' (blank) with a conflicting fee_paise=49900.
    // New code: fee='' → free course → fee_paise = null.
    // Old code: fee_paise=49900 → fee_paise = 49900.
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses", [
            'title' => 'Free Course',
            'fee' => '',
            'fee_paise' => 49900,
        ])
        ->assertRedirect();

    $course = inTenant($tenant, fn () => Course::where('title', 'Free Course')->firstOrFail());

    expect($course->fee_paise)->toBeNull();
});

test('3.3 teacher posts negative fee and validation fails', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3fee.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses", [
            'title' => 'Negative Fee Course',
            'fee' => -1,
        ])
        ->assertSessionHasErrors('fee');
});

test('3.3 teacher posts non-numeric fee and validation fails', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3fee.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses", [
            'title' => 'Bad Fee Course',
            'fee' => 'abc',
        ])
        ->assertSessionHasErrors('fee');
});

test('3.3 course edit form shows fee in rupees with field name fee', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'b3fee.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->create(['fee_paise' => 49900]);

        return [$owner, $course];
    });

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}")
        ->assertOk()
        ->assertSee('name="fee"', false)        // FAILS: current form has name="fee_paise"
        ->assertDontSee('name="fee_paise"', false);
});

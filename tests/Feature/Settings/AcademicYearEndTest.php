<?php

use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;

test('academic year end pre-fills the enrolment form Ends field, and an empty setting leaves it empty', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();

        return [$owner, $course];
    });

    // Positive control: with no academic year end configured, the field stays empty —
    // proves the assertion below is actually driven by the setting, not always present.
    $emptyResponse = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments");
    $emptyResponse->assertOk();
    $emptyResponse->assertDontSee('name="ends_at" id="ends_at" value="20', false);

    freshRequestCycle();

    inTenant($tenant, fn () => $tenant->forceFill(['academic_year_end' => '2027-03-31'])->save());

    $filledResponse = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments");
    $filledResponse->assertOk();
    $filledResponse->assertSee('value="2027-03-31"', false);
});

test('the academic year end default is only ever a starting point; the teacher can still clear or change it per enrolment', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => $tenant->forceFill(['academic_year_end' => '2027-03-31'])->save());

    [$owner, $course, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create(['enrolment_duration' => 'lifetime']);
        $student = User::factory()->student()->create();

        return [$owner, $course, $student];
    });

    // Teacher explicitly clears the pre-filled expiry when submitting the form.
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => now()->toDateString(),
            'ends_at' => '',
        ])
        ->assertRedirect();

    $enrolment = inTenant($tenant, fn () => Enrolment::where('user_id', $student->id)->firstOrFail());
    expect($enrolment->ends_at)->toBeNull();
});

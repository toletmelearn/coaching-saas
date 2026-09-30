<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

function checklistFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    return [$tenant, $domain, $owner];
}

test('an owner with nothing set up sees all six steps as incomplete', function () {
    [, $domain, $owner] = checklistFixture();

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/dashboard");

    $response->assertSee(__('dashboard.getting_started.title'));

    // Bare key-presence check first: Laravel's assertViewHas with a Closure passes
    // Arr::get() (null on a missing key) straight into the closure without checking the
    // key exists — collect(null)->every(...) is vacuously true on an empty collection,
    // so this would otherwise pass even with no 'checklist' view data at all.
    $response->assertViewHas('checklist');
    expect($response->viewData('checklist'))->toHaveCount(6);
    $response->assertViewHas('checklist', function ($checklist) {
        return collect($checklist)->every(fn ($step) => $step['done'] === false);
    });
});

test('each step flips to done once its condition is met', function () {
    [$tenant, $domain, $owner] = checklistFixture();
    [$course, $lesson] = inTenant($tenant, function () {
        $course = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$course, $lesson];
    });

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/dashboard");

    // Same vacuous-truth trap as above: assert the key exists and has all 6 steps before
    // checking that a subset of them flipped to done.
    $response->assertViewHas('checklist');
    expect($response->viewData('checklist'))->toHaveCount(6);
    $response->assertViewHas('checklist', function ($checklist) {
        return collect($checklist)->pluck('done', 'key')->only(['create_course', 'add_lesson', 'add_students', 'enrol_student'])->every(fn ($v) => $v === true);
    });
});

test('staff never see the getting-started card, while the owner does (positive control)', function () {
    [$tenant, $domain, $owner] = checklistFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control first: __() falls back to returning the raw key string when a
    // translation is missing, so "staff never sees the literal untranslated key" would
    // trivially hold even with no feature at all. Requiring the owner to actually see it
    // forces this test to depend on the real feature existing.
    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertSee(__('dashboard.getting_started.title'));

    freshRequestCycle();

    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertDontSee(__('dashboard.getting_started.title'));
});

test('dismissing the checklist hides it and persists', function () {
    [$tenant, $domain, $owner] = checklistFixture();

    // Positive control: the card is visible before dismissal.
    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertSee(__('dashboard.getting_started.title'));

    $this->actingAs($owner, 'tenant')->post("http://{$domain}/manage/getting-started/dismiss")->assertRedirect();

    inTenant($tenant, fn () => expect($tenant->refresh()->getting_started_dismissed_at)->not->toBeNull());

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertDontSee(__('dashboard.getting_started.title'));
});

test('only an owner may dismiss the checklist', function () {
    [$tenant, $domain, $owner] = checklistFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control: the owner can dismiss it.
    $this->actingAs($owner, 'tenant')->post("http://{$domain}/manage/getting-started/dismiss")->assertRedirect();
    inTenant($tenant, fn () => $tenant->forceFill(['getting_started_dismissed_at' => null])->save());

    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/getting-started/dismiss")
        ->assertForbidden();
});

test('checklist counts are tenant-scoped', function () {
    [$tenant, $domain, $owner] = checklistFixture();
    $tenantB = Tenant::factory()->create();
    $tenantB->domains()->create(['domain' => 'tenant-b.coaching.test', 'type' => 'subdomain']);
    inTenant($tenantB, fn () => Course::factory()->create());

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/dashboard");

    // Bare key-presence check first — without it, ->firstWhere() on a missing/null
    // checklist throws "Trying to access array offset on null" (an error, not a clean
    // failure) instead of failing for the intended reason.
    $response->assertViewHas('checklist');
    $step = collect($response->viewData('checklist'))->firstWhere('key', 'create_course');
    expect($step)->not->toBeNull();
    expect($step['done'])->toBeFalse();
});

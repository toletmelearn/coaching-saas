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

    $response->assertViewHas('checklist', function ($checklist) {
        return collect($checklist)->pluck('done', 'key')->only(['create_course', 'add_lesson', 'add_students', 'enrol_student'])->every(fn ($v) => $v === true);
    });
});

test('staff never see the getting-started card', function () {
    [$tenant, $domain] = checklistFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertDontSee(__('dashboard.getting_started.title'));
});

test('dismissing the checklist hides it and persists', function () {
    [$tenant, $domain, $owner] = checklistFixture();

    $this->actingAs($owner, 'tenant')->post("http://{$domain}/manage/getting-started/dismiss")->assertRedirect();

    inTenant($tenant, fn () => expect($tenant->refresh()->getting_started_dismissed_at)->not->toBeNull());

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertDontSee(__('dashboard.getting_started.title'));
});

test('only an owner may dismiss the checklist', function () {
    [$tenant, $domain] = checklistFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

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

    $response->assertViewHas('checklist', function ($checklist) {
        return collect($checklist)->firstWhere('key', 'create_course')['done'] === false;
    });
});

<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

/**
 * Root-cause regression test for the middleware-priority fix in bootstrap/app.php: a
 * request to a require.tenant route with an implicit tenant-scoped Eloquent route
 * parameter must 404 on the central domain, not throw an uncaught
 * MissingTenantContextException (500). Laravel's default middleware priority list runs
 * SubstituteBindings before any route-specific middleware not itself in that list —
 * require.tenant is now pinned immediately before it.
 */
test('a central-domain request for an existing tenant-scoped route (course by slug) 404s, not 500s', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'centralbinding-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $course = inTenant($tenant, fn () => Course::factory()->published()->create());
    $central = config('tenancy.central_domains')[0] ?? 'coaching.test';

    // Positive control: the same course resolves fine on its own tenant's domain.
    $this->get("http://{$domain}/courses/{$course->slug}")->assertOk();

    $this->get("http://{$central}/courses/{$course->slug}")->assertNotFound();
});

test('a central-domain request for the lesson progress heartbeat route 404s, not 500s', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'centralbinding-b.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$lesson, $student] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$lesson, $student];
    });

    $central = config('tenancy.central_domains')[0] ?? 'coaching.test';
    $payload = ['position' => 5, 'duration' => 100, 'played' => 5];

    // Positive control: the same student, same lesson, on the tenant's own domain, is
    // handled normally (200) — proves the central-domain 404 below is real routing/
    // tenancy behaviour, not a route that doesn't exist at all.
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", $payload)
        ->assertOk();

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$central}/lessons/{$lesson->id}/progress", $payload)
        ->assertNotFound();
});

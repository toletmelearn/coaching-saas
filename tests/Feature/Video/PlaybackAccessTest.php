<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;

function lessonWithReadyVideo(Tenant $tenant, bool $freePreview = false): Lesson
{
    return inTenant($tenant, function () use ($freePreview) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => $freePreview,
        ]);
        LessonVideo::factory()->for($lesson)->ready()->create();

        return $lesson;
    });
}

test('an enrolled student sees the player and their watermark identifier', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = lessonWithReadyVideo($tenant);
    $course = inTenant($tenant, fn () => $lesson->course);

    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create(['name' => 'Asha Kumar', 'phone' => '9998887777']);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('Asha Kumar', false);
    $response->assertSee('7777', false); // last 4 digits of phone
});

test('a non-enrolled student never sees the provider video id or a signed URL', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = lessonWithReadyVideo($tenant);
    $course = inTenant($tenant, fn () => $lesson->course);
    $providerVideoId = inTenant($tenant, fn () => $lesson->video->provider_video_id);

    $nonEnrolled = inTenant($tenant, fn () => User::factory()->student()->create());

    $response = $this->actingAs($nonEnrolled, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertForbidden();
    $response->assertDontSee($providerVideoId, false);
    $response->assertDontSee('signature=', false);
});

test('a guest is redirected to login and never sees the provider video id', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = lessonWithReadyVideo($tenant);
    $course = inTenant($tenant, fn () => $lesson->course);
    $providerVideoId = inTenant($tenant, fn () => $lesson->video->provider_video_id);

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertRedirect("http://{$domain}/login");
    $response->assertDontSee($providerVideoId, false);
});

test('a free-preview lesson with a protected video is playable by a guest', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = lessonWithReadyVideo($tenant, freePreview: true);
    $course = inTenant($tenant, fn () => $lesson->course);

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
});

test('tenant B cannot play tenant A\'s video via any URL, even a valid-looking one on tenant A\'s domain', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';
    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    $lessonB = lessonWithReadyVideo($tenantB, freePreview: true);
    $courseB = inTenant($tenantB, fn () => $lessonB->course);

    // Positive control: the lesson resolves fine on its own tenant's domain
    $this->get("http://{$domainB}/courses/{$courseB->slug}/lessons/{$lessonB->id}")->assertOk();

    $studentA = inTenant($tenantA, fn () => User::factory()->student()->create());

    // Negative: tenant B's course/lesson requested against tenant A's hostname 404s —
    // route-model binding is scoped by the tenant resolved from the hostname, so
    // courseB/lessonB simply don't exist in tenant A's scope.
    $this->actingAs($studentA, 'tenant')
        ->get("http://{$domainA}/courses/{$courseB->slug}/lessons/{$lessonB->id}")
        ->assertNotFound();
});

test('the provider video id owner/staff preview shows "Preview" prefix, not a bare name', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = lessonWithReadyVideo($tenant);
    $course = inTenant($tenant, fn () => $lesson->course);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create(['name' => 'Priya Owner']));

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('Preview', false);
    $response->assertSee('Priya Owner', false);
});

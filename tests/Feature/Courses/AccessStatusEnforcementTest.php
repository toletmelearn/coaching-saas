<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function makePaidLessonWithAttachment(Tenant $tenant): array
{
    return inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]);
        $attachment = LessonAttachment::factory()->for($lesson)->create();

        return [$course, $lesson, $attachment];
    });
}

test('disabling an enrolled student logs them out of a paid lesson and its PDF on the next request', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $lesson, $attachment] = makePaidLessonWithAttachment($tenant);

    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create([
            'email' => 'disable-me@example.com',
            'password' => Hash::make('password123'),
        ]);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    // Real login (no actingAs) so the session actually carries this user's id, not an
    // in-memory PHP object the guard was just handed directly.
    $this->post("http://{$domain}/login", [
        'identifier' => 'disable-me@example.com',
        'password' => 'password123',
    ])->assertRedirect();
    $this->assertAuthenticatedAs($student, 'tenant');

    // Positive control: enrolled + active student can open the paid lesson and its PDF
    $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")->assertOk();
    $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}/attachments/{$attachment->id}")->assertOk();

    // Disable the student directly in the DB (not via the in-memory $student object, and
    // not via actingAs as a different actor) — the only thing that should matter to the
    // next request is what's actually in the database.
    inTenant($tenant, fn () => $student->forceFill(['status' => 'disabled'])->save());

    freshRequestCycle();

    // No actingAs() and no refresh() here: the still-logged-in session must re-resolve the
    // user from the DB (via TenantUserProvider) on this new request and find them disabled.
    $lessonResponse = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");
    $lessonResponse->assertRedirect("http://{$domain}/login");
    $this->assertGuest('tenant');

    freshRequestCycle();

    $attachmentResponse = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}/attachments/{$attachment->id}");
    $attachmentResponse->assertRedirect("http://{$domain}/login");
    $this->assertGuest('tenant');
});

test('must_change_password redirects away from a paid lesson and its PDF until changed', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $lesson, $attachment] = makePaidLessonWithAttachment($tenant);

    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->mustChangePassword()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    $lessonResponse = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");
    $lessonResponse->assertRedirect("http://{$domain}/auth/change-password");

    $attachmentResponse = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}/attachments/{$attachment->id}");
    $attachmentResponse->assertRedirect("http://{$domain}/auth/change-password");

    // After changing the password, the lesson opens (positive control resolving the redirect above).
    $this->actingAs($student, 'tenant')
        ->post("http://{$domain}/auth/change-password", [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

    freshRequestCycle();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertOk();
});

test('guest can still open a free-preview lesson with active.tenant.user and must.change.password applied', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'M7lc1UVf-VE',
        ]);
    });
    $course = inTenant($tenant, fn () => $lesson->course);

    $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")->assertOk();
});

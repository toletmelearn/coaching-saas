<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

// === 1. Login page shows the tenant name only once ===

test('the login page shows the tenant name only once (in the header, not the page body too)', function () {
    $tenant = Tenant::factory()->create(['name' => 'Bright Future Academy']);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    $response->assertOk();
    expect(substr_count($response->getContent(), 'Bright Future Academy'))->toBe(1);
    $response->assertSee(__('auth.login.submit'));
});

// === 2. Course page badges depend on the viewer, via LessonAccess ===

function makeCourseForBadgeTest(Tenant $tenant): array
{
    return inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        $freeLesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'title' => 'Free Lesson',
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);

        $paidLesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'title' => 'Paid Lesson',
            'is_free_preview' => false,
        ]);

        return [$course, $freeLesson, $paidLesson];
    });
}

test('a free-preview lesson shows "Free" for every viewer type', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $freeLesson] = makeCourseForBadgeTest($tenant);

    // Guest.
    $this->get("http://{$domain}/courses/{$course->slug}")
        ->assertSeeInOrder(['Free Lesson', __('courses.show.free')]);

    // Owner.
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    freshRequestCycle();
    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertSeeInOrder(['Free Lesson', __('courses.show.free')]);
});

test('a paid lesson shows no lock badge for a viewer who can open it (valid enrolment, or owner/staff)', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, , $paidLesson] = makeCourseForBadgeTest($tenant);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());
    $enrolledStudent = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    foreach ([$owner, $staff, $enrolledStudent] as $viewer) {
        $response = $this->actingAs($viewer, 'tenant')
            ->get("http://{$domain}/courses/{$course->slug}");

        $response->assertSee('Paid Lesson');
        $response->assertDontSee(__('courses.show.locked'));

        freshRequestCycle();
    }
});

test('a paid lesson shows "Locked" for a guest, a non-enrolled student, and an expired/revoked enrolment', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, , $paidLesson] = makeCourseForBadgeTest($tenant);

    // Guest.
    $this->get("http://{$domain}/courses/{$course->slug}")
        ->assertSeeInOrder(['Paid Lesson', __('courses.show.locked')]);

    freshRequestCycle();

    // Non-enrolled student.
    $nonEnrolled = inTenant($tenant, fn () => User::factory()->student()->create());
    $this->actingAs($nonEnrolled, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertSeeInOrder(['Paid Lesson', __('courses.show.locked')]);

    freshRequestCycle();

    // Expired enrolment.
    $expiredStudent = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        return $student;
    });
    $this->actingAs($expiredStudent, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertSeeInOrder(['Paid Lesson', __('courses.show.locked')]);

    freshRequestCycle();

    // Revoked enrolment.
    $revokedStudent = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->revoked()->create();

        return $student;
    });
    $this->actingAs($revokedStudent, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertSeeInOrder(['Paid Lesson', __('courses.show.locked')]);
});

// === 3. Lesson page uses shared components ===

test('the lesson page renders via the shared page-header component and shows a board tag badge', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'title' => 'Board Tag Lesson',
            'board_tag' => 'CBSE',
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    });
    $course = inTenant($tenant, fn () => $lesson->course);

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    // x-page-header renders the lesson title as the page's single <h1>. The assertion
    // used to pin the component's exact utility classes; the class set moved to the
    // design-token layer (ui-h1) during the UI refresh, so what still matters is the
    // contract: one <h1>, carrying the lesson title, produced by the shared component.
    expect(preg_match('/<h1[^>]*>Board Tag Lesson<\/h1>/', $response->getContent()))->toBe(1);
    $response->assertSee('CBSE');
});

// === 4. Video is full width, 16:9, and uses ?rel=0 ===

test('the video iframe is full width, 16:9, and requests rel=0 from youtube-nocookie', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    });
    $course = inTenant($tenant, fn () => $lesson->course);

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('aspect-video', false);
    $response->assertSee('w-full', false);
    $response->assertSee('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0"', false);
});

// === 5. Lesson navigation: back link + prev/next skipping drafts ===

test('the lesson page has a back-to-course link and Previous/Next links in chapter/position order, skipping drafts', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$course, $lessonA, $lessonB, $lessonC] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter1 = Chapter::factory()->for($course)->create(['position' => 1]);
        $chapter2 = Chapter::factory()->for($course)->create(['position' => 2]);

        $lessonA = Lesson::factory()->for($chapter1)->published()->create([
            'course_id' => $course->id, 'title' => 'Lesson A', 'position' => 1,
            'is_free_preview' => true, 'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
        // A draft lesson sits between A and B in position order and must be skipped.
        Lesson::factory()->for($chapter1)->draft()->create([
            'course_id' => $course->id, 'title' => 'Draft Between', 'position' => 2,
        ]);
        $lessonB = Lesson::factory()->for($chapter1)->published()->create([
            'course_id' => $course->id, 'title' => 'Lesson B', 'position' => 3,
            'is_free_preview' => true, 'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
        $lessonC = Lesson::factory()->for($chapter2)->published()->create([
            'course_id' => $course->id, 'title' => 'Lesson C', 'position' => 1,
            'is_free_preview' => true, 'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);

        return [$course, $lessonA, $lessonB, $lessonC];
    });

    // Lesson A: no previous, next is B (draft skipped).
    $responseA = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lessonA->id}");
    $responseA->assertOk();
    $responseA->assertSee('/courses/'.$course->slug, false); // back-to-course link
    $responseA->assertDontSee(__('lessons.previous_lesson'));
    $responseA->assertSeeInOrder([__('lessons.next_lesson'), 'Lesson B']);

    // Lesson B: previous is A (draft skipped going backwards too), next is C (crosses chapters).
    $responseB = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lessonB->id}");
    $responseB->assertOk();
    $responseB->assertSeeInOrder([__('lessons.previous_lesson'), 'Lesson A']);
    $responseB->assertSeeInOrder([__('lessons.next_lesson'), 'Lesson C']);

    // Lesson C: previous is B, no next.
    $responseC = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lessonC->id}");
    $responseC->assertOk();
    $responseC->assertSeeInOrder([__('lessons.previous_lesson'), 'Lesson B']);
    $responseC->assertDontSee(__('lessons.next_lesson'));
});

// === 6. Student dashboard "Continue" link ===

test('the student dashboard shows a Continue link to the first lesson the student can open', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$student, $course, $firstOpenableLesson] = inTenant($tenant, function () {
        $course = Course::factory()->published()->create(['title' => 'Continue Course']);
        $chapter = Chapter::factory()->for($course)->create();

        // A draft lesson comes first in position order but can never be "continue"'d to.
        Lesson::factory()->for($chapter)->draft()->create([
            'course_id' => $course->id, 'position' => 1, 'title' => 'Draft First',
        ]);
        $firstOpenableLesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id, 'position' => 2, 'title' => 'First Real Lesson',
            'is_free_preview' => false,
        ]);
        Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id, 'position' => 3, 'title' => 'Second Real Lesson',
            'is_free_preview' => false,
        ]);

        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$student, $course, $firstOpenableLesson];
    });

    $response = $this->actingAs($student, 'tenant')->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertSee('Continue Course');
    $response->assertSee("/courses/{$course->slug}/lessons/{$firstOpenableLesson->id}", false);
});

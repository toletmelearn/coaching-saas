<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

/**
 * @param  Closure(): Course  $courseFactory  e.g. fn () => Course::factory()->published()->create()
 * @param  Closure(Course): Lesson  $lessonFactory  e.g. fn ($chapter) => Lesson::factory()->for($chapter)->published()->create([...])
 */
function makeLesson(Tenant $tenant, Closure $courseFactory, Closure $lessonFactory): Lesson
{
    return inTenant($tenant, function () use ($courseFactory, $lessonFactory) {
        $course = $courseFactory();
        $chapter = Chapter::factory()->for($course)->create();

        return $lessonFactory($chapter, $course);
    });
}

test('guest can view a free-preview published lesson', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]),
    );

    $course = inTenant($tenant, fn () => $lesson->course);

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
});

test('guest is redirected to login for a paid lesson', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: a free-preview lesson in a sibling course is publicly viewable
    $freeLesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]),
    );
    $freeCourse = inTenant($tenant, fn () => $freeLesson->course);
    $this->get("http://{$domain}/courses/{$freeCourse->slug}/lessons/{$freeLesson->id}")->assertOk();

    $paidLesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]),
    );
    $paidCourse = inTenant($tenant, fn () => $paidLesson->course);

    $response = $this->get("http://{$domain}/courses/{$paidCourse->slug}/lessons/{$paidLesson->id}");

    $response->assertRedirect("http://{$domain}/login");
});

test('guest and student get 404 for a draft course', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->draft()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control: the same URL shape resolves fine for a published course
    $publishedCourse = inTenant($tenant, fn () => Course::factory()->published()->create());
    $this->get("http://{$domain}/courses/{$publishedCourse->slug}")->assertOk();

    $this->get("http://{$domain}/courses/{$course->slug}")->assertNotFound();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertNotFound();
});

test('guest and student get 404 for a draft lesson', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->draft()->create([
            'course_id' => $course->id,
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control: a published lesson in the same course resolves fine
    $publishedLesson = inTenant($tenant, function () use ($course) {
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    });
    $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$publishedLesson->id}")->assertOk();

    $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")->assertNotFound();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertNotFound();
});

test('non-enrolled student gets 404 for an archived course', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->archived()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control: a published course's own catalogue page works
    $publishedCourse = inTenant($tenant, fn () => Course::factory()->published()->create());
    $this->get("http://{$domain}/courses/{$publishedCourse->slug}")->assertOk();

    $this->get("http://{$domain}/courses/{$course->slug}")->assertNotFound();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}")
        ->assertNotFound();
});

test('enrolled student can view a paid lesson; non-enrolled student gets 403', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);

    $enrolledStudent = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    $this->actingAs($enrolledStudent, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertOk();

    $nonEnrolledStudent = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($nonEnrolledStudent, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertForbidden();
});

test('expired enrolment gets 403 with the end date shown', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);

    $endedAt = now()->subDay();

    $student = inTenant($tenant, function () use ($course, $endedAt) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => $endedAt,
        ]);

        return $student;
    });

    // Positive control: a currently-valid enrolment in a sibling course is allowed
    $validLesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]),
    );
    $validCourse = inTenant($tenant, fn () => $validLesson->course);
    inTenant($tenant, fn () => Enrolment::factory()->for($validCourse)->for($student, 'user')->active()->create());
    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$validCourse->slug}/lessons/{$validLesson->id}")
        ->assertOk();

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertForbidden();
    $response->assertSee($endedAt->timezone('Asia/Kolkata')->format('d M Y'));
});

test('revoked enrolment gets 403', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);

    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->revoked()->create();

        return $student;
    });

    // Positive control: an active enrolment on the same course would be allowed
    $activeStudent = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });
    $this->actingAs($activeStudent, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertOk();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertForbidden();
});

test('enrolment with starts_at in the future gets 403 until the start date', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);

    $futureStart = now()->addWeek();

    $student = inTenant($tenant, function () use ($course, $futureStart) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'starts_at' => $futureStart,
        ]);

        return $student;
    });

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertForbidden();

    $this->travelTo($futureStart->clone()->addMinute());

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertOk();

    $this->travelBack();
});

test('enrolled student keeps access to an archived course until ends_at', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->archived()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);

    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
        ]);

        return $student;
    });

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertOk();

    // Non-enrolled student gets 404 on the same archived course (positive control already above)
    $nonEnrolled = inTenant($tenant, fn () => User::factory()->student()->create());
    $this->actingAs($nonEnrolled, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertNotFound();
});

test('owner and staff can view draft lessons', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = makeLesson(
        $tenant,
        fn () => Course::factory()->draft()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->draft()->create([
            'course_id' => $course->id,
        ]),
    );
    $course = inTenant($tenant, fn () => $lesson->course);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertOk();

    freshRequestCycle();

    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertOk();
});

test('lesson from course A requested under course B URL gets 404', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lessonA = makeLesson(
        $tenant,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]),
    );
    $courseA = inTenant($tenant, fn () => $lessonA->course);
    $courseB = inTenant($tenant, fn () => Course::factory()->published()->create());

    // Positive control: lesson A under its own course's URL resolves fine
    $this->get("http://{$domain}/courses/{$courseA->slug}/lessons/{$lessonA->id}")->assertOk();

    $this->get("http://{$domain}/courses/{$courseB->slug}/lessons/{$lessonA->id}")->assertNotFound();
});

test('tenant A student cannot view tenant B lesson or attachment via a valid-looking URL', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';
    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    $lessonB = makeLesson(
        $tenantB,
        fn () => Course::factory()->published()->create(),
        fn (Chapter $chapter, Course $course) => Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]),
    );
    $courseB = inTenant($tenantB, fn () => $lessonB->course);

    // Positive control: the lesson resolves fine on its own tenant's domain
    $this->get("http://{$domainB}/courses/{$courseB->slug}/lessons/{$lessonB->id}")->assertOk();

    $studentA = inTenant($tenantA, fn () => User::factory()->student()->create());

    $this->actingAs($studentA, 'tenant')
        ->get("http://{$domainA}/courses/{$courseB->slug}/lessons/{$lessonB->id}")
        ->assertNotFound();
});

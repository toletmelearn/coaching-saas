<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function teacherProgressFixture(int $lessonsCount = 3): array
{
    $tenant = Tenant::factory()->create();
    $domain = strtolower(Str::random(8)).'.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $lessons] = inTenant($tenant, function () use ($lessonsCount) {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lessons = collect(range(1, $lessonsCount))->map(
            fn () => Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id])
        );

        return [$owner, $course, $lessons];
    });

    return [$tenant, $domain, $owner, $course, $lessons];
}

test('owner sees the progress table with correct per-student counts', function () {
    [$tenant, $domain, $owner, $course, $lessons] = teacherProgressFixture(2);

    inTenant($tenant, function () use ($course, $lessons) {
        $student = User::factory()->student()->create(['name' => 'Rita Sharma']);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
        LessonProgress::factory()->for($lessons->first())->for($course)->for($student, 'user')->manuallyCompleted()->create();
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress");

    $response->assertOk();
    $response->assertSee('Rita Sharma');
    $response->assertSee('1 of 2', false);
});

test('staff can view the progress table', function () {
    [$tenant, $domain, , $course] = teacherProgressFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress")
        ->assertOk();
});

test('a student is forbidden from the progress table', function () {
    [$tenant, $domain, , $course] = teacherProgressFixture();
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress")
        ->assertForbidden();
});

test('a guest is redirected from the progress table', function () {
    [, $domain, , $course] = teacherProgressFixture();

    $this->get("http://{$domain}/manage/courses/{$course->id}/progress")
        ->assertRedirect("http://{$domain}/login");
});

test('the "not active for 7+ days" filter matches only inactive students', function () {
    [$tenant, $domain, $owner, $course, $lessons] = teacherProgressFixture();

    inTenant($tenant, function () use ($course, $lessons) {
        $stale = User::factory()->student()->create(['name' => 'Stale Student']);
        Enrolment::factory()->for($course)->for($stale, 'user')->active()->create();
        LessonProgress::factory()->for($lessons->first())->for($course)->for($stale, 'user')->inactiveSince(10)->create();

        $fresh = User::factory()->student()->create(['name' => 'Fresh Student']);
        Enrolment::factory()->for($course)->for($fresh, 'user')->active()->create();
        LessonProgress::factory()->for($lessons->first())->for($course)->for($fresh, 'user')->inactiveSince(1)->create();
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress?filter=inactive_7d");

    $response->assertSee('Stale Student');
    $response->assertDontSee('Fresh Student');
});

test('the "not started" filter matches only students with no accepted activity', function () {
    [$tenant, $domain, $owner, $course, $lessons] = teacherProgressFixture();

    inTenant($tenant, function () use ($course, $lessons) {
        $notStarted = User::factory()->student()->create(['name' => 'Not Started Student']);
        Enrolment::factory()->for($course)->for($notStarted, 'user')->active()->create();

        // Deliberately NOT a substring of "Not Started Student" (an earlier version of
        // this test used "Started Student", which "Not Started Student" itself
        // contains — making the assertDontSee below always fail regardless of whether
        // filtering actually worked).
        $active = User::factory()->student()->create(['name' => 'Active Learner']);
        Enrolment::factory()->for($course)->for($active, 'user')->active()->create();
        LessonProgress::factory()->for($lessons->first())->for($course)->for($active, 'user')->inactiveSince(0)->create();
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress?filter=not_started");

    $response->assertSee('Not Started Student');
    $response->assertDontSee('Active Learner');
});

test('the progress table is paginated at 25 per page', function () {
    [$tenant, $domain, $owner, $course] = teacherProgressFixture();

    inTenant($tenant, function () use ($course) {
        User::factory()->count(30)->student()->create()->each(
            fn (User $student) => Enrolment::factory()->for($course)->for($student, 'user')->active()->create()
        );
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress");

    $response->assertOk();
    // The pagination footer's total-results figure proves the full 30-student result
    // set is known, while exactly 25 row markers proves the page itself was truncated
    // to 25 — together these prove real pagination, not just an unpaginated list of
    // everyone. Laravel's default tailwind pagination view puts the total inside its
    // own <span>, so "of 30" never appears as contiguous text.
    $response->assertSee('<span class="font-medium">30</span>', false);
    expect(substr_count($response->getContent(), 'data-student-row'))->toBe(25);
});

test('viewing the progress table for 30 students issues a bounded number of queries (no N+1)', function () {
    [$tenant, $domain, $owner, $course, $lessons] = teacherProgressFixture();

    inTenant($tenant, function () use ($course, $lessons) {
        User::factory()->count(30)->student()->create()->each(function (User $student) use ($course, $lessons) {
            Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
            LessonProgress::factory()->for($lessons->first())->for($course)->for($student, 'user')->inactiveSince(0)->create();
        });
    });

    DB::enableQueryLog();
    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress")
        ->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(15);
});

test('the per-student page shows every published lesson with its status', function () {
    [$tenant, $domain, $owner, $course, $lessons] = teacherProgressFixture(2);

    $student = inTenant($tenant, function () use ($course, $lessons) {
        $student = User::factory()->student()->create(['name' => 'Deepak Verma']);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
        LessonProgress::factory()->for($lessons->first())->for($course)->for($student, 'user')->manuallyCompleted()->create();

        return $student;
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress/{$student->id}");

    $response->assertOk();
    $response->assertSee($lessons->get(0)->title);
    $response->assertSee($lessons->get(1)->title);
});

test('a student cannot view another student\'s per-student progress page', function () {
    [$tenant, $domain, , $course, $lessons] = teacherProgressFixture();

    $target = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });
    $requester = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($requester, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress/{$target->id}")
        ->assertForbidden();
});

test('tenant isolation: tenant A\'s domain cannot resolve tenant B\'s course id for progress', function () {
    [$tenantA, $domainA, $ownerA, $courseA] = teacherProgressFixture();
    [, , , $courseB] = teacherProgressFixture();

    // Positive control: tenant A's own owner viewing tenant A's own course, on tenant
    // A's own domain, works — proves the negative below is real route-scoping, not a
    // route that's broken outright.
    $this->actingAs($ownerA, 'tenant')
        ->get("http://{$domainA}/manage/courses/{$courseA->id}/progress")
        ->assertOk();

    // Negative: the SAME actor, SAME domain, but tenant B's course id — {course} route
    // binding is scoped to tenant A (resolved from domainA), so tenant B's course
    // simply doesn't exist in that scope. Deliberately not using actingAs() with a
    // cross-tenant user here (that bypasses the real tenant-scoped session/user-provider
    // resolution entirely and would never happen via an actual login), matching the
    // established pattern in tests/Feature/Video/PlaybackAccessTest.php.
    $this->actingAs($ownerA, 'tenant')
        ->get("http://{$domainA}/manage/courses/{$courseB->id}/progress")
        ->assertNotFound();
});

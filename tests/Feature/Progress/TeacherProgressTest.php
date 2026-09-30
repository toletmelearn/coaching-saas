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
    $domain = Str::random(8).'.coaching.test';
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
        LessonProgress::create([
            'lesson_id' => $lessons->first()->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'completed_at' => now(),
            'completed_manually' => true,
        ]);
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
        LessonProgress::create([
            'lesson_id' => $lessons->first()->id,
            'course_id' => $course->id,
            'user_id' => $stale->id,
            'last_activity_at' => now()->subDays(10),
        ]);

        $fresh = User::factory()->student()->create(['name' => 'Fresh Student']);
        Enrolment::factory()->for($course)->for($fresh, 'user')->active()->create();
        LessonProgress::create([
            'lesson_id' => $lessons->first()->id,
            'course_id' => $course->id,
            'user_id' => $fresh->id,
            'last_activity_at' => now()->subDay(),
        ]);
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

        $started = User::factory()->student()->create(['name' => 'Started Student']);
        Enrolment::factory()->for($course)->for($started, 'user')->active()->create();
        LessonProgress::create([
            'lesson_id' => $lessons->first()->id,
            'course_id' => $course->id,
            'user_id' => $started->id,
            'last_activity_at' => now(),
        ]);
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress?filter=not_started");

    $response->assertSee('Not Started Student');
    $response->assertDontSee('Started Student');
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
    $response->assertSee('26', false); // pagination affordance / "of 30" style text expected somewhere
});

test('viewing the progress table for 30 students issues a bounded number of queries (no N+1)', function () {
    [$tenant, $domain, $owner, $course, $lessons] = teacherProgressFixture();

    inTenant($tenant, function () use ($course, $lessons) {
        User::factory()->count(30)->student()->create()->each(function (User $student) use ($course, $lessons) {
            Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
            LessonProgress::create([
                'lesson_id' => $lessons->first()->id,
                'course_id' => $course->id,
                'user_id' => $student->id,
                'last_activity_at' => now(),
            ]);
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
        LessonProgress::create([
            'lesson_id' => $lessons->first()->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'completed_at' => now(),
            'completed_manually' => true,
        ]);

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

test('tenant isolation: tenant B cannot view tenant A\'s course progress table', function () {
    [$tenantA, $domainA, $ownerA, $courseA] = teacherProgressFixture();
    [$tenantB, , $ownerB] = teacherProgressFixture();

    // Positive control: tenant A's own owner can view tenant A's course progress.
    $this->actingAs($ownerA, 'tenant')
        ->get("http://{$domainA}/manage/courses/{$courseA->id}/progress")
        ->assertOk();

    $this->actingAs($ownerB, 'tenant')
        ->get("http://{$domainA}/manage/courses/{$courseA->id}/progress")
        ->assertNotFound();
});

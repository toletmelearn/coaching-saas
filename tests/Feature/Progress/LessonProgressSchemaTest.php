<?php

use App\Exceptions\LessonProgressCourseMismatchException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function progressFixtures(Tenant $tenant): array
{
    return inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $student = User::factory()->student()->create();

        return [$course, $lesson, $student];
    });
}

// === Composite FK / uniqueness ===

test('lesson progress cannot reference a lesson from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    [$courseA, $lessonA] = progressFixtures($tenantA);
    [$courseB, $lessonB, $studentB] = progressFixtures($tenantB);

    // Positive control: own-tenant row succeeds.
    inTenant($tenantB, function () use ($lessonB, $courseB, $studentB) {
        expect(LessonProgress::create([
            'lesson_id' => $lessonB->id,
            'course_id' => $courseB->id,
            'user_id' => $studentB->id,
        ]))->not->toBeNull();
    });

    expect(fn () => inTenant($tenantB, function () use ($lessonA, $courseA, $studentB) {
        LessonProgress::create([
            'lesson_id' => $lessonA->id,
            'course_id' => $courseA->id,
            'user_id' => $studentB->id,
        ]);
    }))->toThrow(QueryException::class);
});

test('the composite FK rejects a course from another tenant independently of model events', function () {
    // Bypasses Eloquent (and therefore LessonProgress's own saving hook) entirely, so
    // this proves the DB-level composite FK (tenant_id, course_id) -> courses(tenant_id,
    // id) rejects a cross-tenant course_id on its own — not just the model-level guard
    // that the next test covers, which would otherwise mask this at the app layer.
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    [$courseA] = progressFixtures($tenantA);
    [$courseB, $lessonB, $studentB] = progressFixtures($tenantB);
    $studentB2 = inTenant($tenantB, fn () => User::factory()->student()->create());

    // Positive control: a raw insert with tenantB's own course_id succeeds.
    expect(fn () => DB::table('lesson_progress')->insert([
        'tenant_id' => $tenantB->id,
        'lesson_id' => $lessonB->id,
        'course_id' => $courseB->id,
        'user_id' => $studentB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->not->toThrow(QueryException::class);

    // A different (lesson, user) pair from the positive control above, so this insert
    // can only fail on the cross-tenant course_id — never on the (tenant, lesson, user)
    // unique constraint, which would otherwise mask what this test is proving.
    expect(fn () => DB::table('lesson_progress')->insert([
        'tenant_id' => $tenantB->id,
        'lesson_id' => $lessonB->id,
        'course_id' => $courseA->id,
        'user_id' => $studentB2->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('lesson progress cannot reference a course from another tenant (model path)', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    [$courseA] = progressFixtures($tenantA);
    [, $lessonB, $studentB] = progressFixtures($tenantB);

    expect(fn () => inTenant($tenantB, function () use ($lessonB, $courseA, $studentB) {
        LessonProgress::create([
            'lesson_id' => $lessonB->id,
            'course_id' => $courseA->id,
            'user_id' => $studentB->id,
        ]);
    }))->toThrow(LessonProgressCourseMismatchException::class);
});

test('lesson progress cannot reference a user from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    [, , $studentA] = progressFixtures($tenantA);
    [$courseB, $lessonB] = progressFixtures($tenantB);

    expect(fn () => inTenant($tenantB, function () use ($lessonB, $courseB, $studentA) {
        LessonProgress::create([
            'lesson_id' => $lessonB->id,
            'course_id' => $courseB->id,
            'user_id' => $studentA->id,
        ]);
    }))->toThrow(QueryException::class);
});

test('a student has at most one progress row per lesson', function () {
    $tenant = Tenant::factory()->create();
    [$course, $lesson, $student] = progressFixtures($tenant);

    inTenant($tenant, function () use ($course, $lesson, $student) {
        expect(LessonProgress::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]))->not->toBeNull();

        expect(fn () => LessonProgress::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]))->toThrow(QueryException::class);
    });
});

test('course_id must match the lesson\'s own course_id', function () {
    $tenant = Tenant::factory()->create();
    [$course, $lesson, $student] = progressFixtures($tenant);

    $otherCourse = inTenant($tenant, fn () => Course::factory()->published()->create());

    expect(fn () => inTenant($tenant, function () use ($lesson, $otherCourse, $student) {
        LessonProgress::create([
            'lesson_id' => $lesson->id,
            'course_id' => $otherCourse->id,
            'user_id' => $student->id,
        ]);
    }))->toThrow(LessonProgressCourseMismatchException::class);

    // Positive control: matching course_id succeeds.
    inTenant($tenant, function () use ($lesson, $course, $student) {
        expect(LessonProgress::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]))->not->toBeNull();
    });
});

// === Mass assignment guards ===

test('guarded progress fields cannot be set via mass assignment', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    [$course, $lesson, $student] = progressFixtures($tenant);

    inTenant($tenant, function () use ($tenant, $course, $lesson, $student, $otherTenant) {
        $viaMassAssignment = LessonProgress::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'tenant_id' => $otherTenant->id,
            'watched_seconds' => 9999,
            'completed_at' => now(),
            'completed_manually' => true,
            'last_heartbeat_at' => now(),
            'last_activity_at' => now(),
        ]);

        expect($viaMassAssignment->tenant_id)->toBe($tenant->id)
            ->and($viaMassAssignment->watched_seconds)->toBe(0)
            ->and($viaMassAssignment->completed_at)->toBeNull()
            ->and($viaMassAssignment->completed_manually)->toBeFalse()
            ->and($viaMassAssignment->last_heartbeat_at)->toBeNull()
            ->and($viaMassAssignment->last_activity_at)->toBeNull();
    });
});

// === Cross-tenant visibility ===

test('tenant A\'s progress rows are never visible to tenant B', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    [$courseA, $lessonA, $studentA] = progressFixtures($tenantA);
    progressFixtures($tenantB);

    inTenant($tenantA, fn () => LessonProgress::create([
        'lesson_id' => $lessonA->id,
        'course_id' => $courseA->id,
        'user_id' => $studentA->id,
    ]));

    // Positive control: visible within its own tenant.
    inTenant($tenantA, fn () => expect(LessonProgress::count())->toBe(1));

    inTenant($tenantB, fn () => expect(LessonProgress::count())->toBe(0));
});

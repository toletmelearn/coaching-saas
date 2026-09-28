<?php

use App\Enums\CourseStatus;
use App\Enums\EnrolmentStatus;
use App\Exceptions\LessonCourseMismatchException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;

// === Composite FK: lesson -> chapter ===

test('a lesson cannot reference a chapter from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $chapterA = inTenant($tenantA, function () {
        $course = Course::factory()->create();

        return Chapter::factory()->for($course)->create();
    });

    // Positive control: a lesson referencing a chapter in its own tenant succeeds
    inTenant($tenantB, function () {
        $course = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create();

        expect(Lesson::create([
            'chapter_id' => $chapter->id,
            'course_id' => $course->id,
            'title' => 'Own tenant lesson',
        ]))->not->toBeNull();
    });

    // Negative: chapter_id points at tenant A's chapter while operating as tenant B
    expect(fn () => inTenant($tenantB, function () use ($chapterA) {
        $course = Course::factory()->create();

        Lesson::create([
            'chapter_id' => $chapterA->id,
            'course_id' => $course->id,
            'title' => 'Cross-tenant lesson',
        ]);
    }))->toThrow(QueryException::class);
});

// === Composite FK: enrolment -> course / user ===

test('an enrolment cannot reference a course from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $courseA = inTenant($tenantA, fn () => Course::factory()->published()->create());

    // Positive control: an enrolment referencing a course in its own tenant succeeds
    inTenant($tenantB, function () {
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();

        expect(Enrolment::create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'starts_at' => now(),
        ]))->not->toBeNull();
    });

    expect(fn () => inTenant($tenantB, function () use ($courseA) {
        $student = User::factory()->student()->create();

        Enrolment::create([
            'course_id' => $courseA->id,
            'user_id' => $student->id,
            'starts_at' => now(),
        ]);
    }))->toThrow(QueryException::class);
});

test('an enrolment cannot reference a user from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $studentA = inTenant($tenantA, fn () => User::factory()->student()->create());

    // Positive control: an enrolment referencing a user in its own tenant succeeds
    inTenant($tenantB, function () {
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();

        expect(Enrolment::create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'starts_at' => now(),
        ]))->not->toBeNull();
    });

    expect(fn () => inTenant($tenantB, function () use ($studentA) {
        $course = Course::factory()->published()->create();

        Enrolment::create([
            'course_id' => $course->id,
            'user_id' => $studentA->id,
            'starts_at' => now(),
        ]);
    }))->toThrow(QueryException::class);
});

// === lesson.course_id must match its chapter's course ===

test('lesson.course_id must match its chapter course', function () {
    $tenant = Tenant::factory()->create();

    inTenant($tenant, function () {
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create();

        // Positive control: course_id matching the chapter's own course succeeds
        expect(Lesson::create([
            'chapter_id' => $chapter->id,
            'course_id' => $course->id,
            'title' => 'Matching lesson',
        ]))->not->toBeNull();

        // Negative: course_id does not match the chapter's actual course
        expect(fn () => Lesson::create([
            'chapter_id' => $chapter->id,
            'course_id' => $otherCourse->id,
            'title' => 'Mismatched lesson',
        ]))->toThrow(LessonCourseMismatchException::class);
    });
});

// === Slug uniqueness ===

test('course slug is unique per tenant and the same slug is allowed in two tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => Course::factory()->create(['slug' => 'physics-11']));

    // Positive control: the same slug in a different tenant succeeds
    inTenant($tenantB, fn () => Course::factory()->create(['slug' => 'physics-11']));

    expect(fn () => inTenant($tenantA, fn () => Course::factory()->create(['slug' => 'physics-11'])))
        ->toThrow(QueryException::class);
});

// === Mass assignment guards ===

test('course status, published_at, tenant_id and created_by are not mass-assignable', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    inTenant($tenant, function () use ($otherTenant) {
        $owner = User::factory()->owner()->create();

        // Positive control: the approved path (factory state -> forceFill) can set these
        $viaState = Course::factory()->published()->create(['created_by' => $owner->id]);
        expect($viaState->status)->toBe(CourseStatus::Published)
            ->and($viaState->created_by)->toBe($owner->id);

        // Negative: raw mass-assignment must not set any of these guarded fields
        $viaMassAssignment = Course::create([
            'title' => 'Mass Assignment Test',
            'slug' => 'mass-assignment-test',
            'status' => CourseStatus::Published->value,
            'published_at' => now(),
            'tenant_id' => $otherTenant->id,
            'created_by' => $owner->id + 999,
        ]);

        expect($viaMassAssignment->status)->toBe(CourseStatus::Draft)
            ->and($viaMassAssignment->published_at)->toBeNull()
            ->and($viaMassAssignment->tenant_id)->toBe($tenant->id)
            ->and($viaMassAssignment->created_by)->toBeNull();
    });
});

test('enrolment status, enrolled_by, revoked_at and revoked_by are not mass-assignable', function () {
    $tenant = Tenant::factory()->create();

    inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create();
        $owner = User::factory()->owner()->create();

        // Positive control: the approved path (factory state -> forceFill) can set these
        $viaState = Enrolment::factory()->for($course)->for($student, 'user')->revoked()->create([
            'enrolled_by' => $owner->id,
        ]);
        expect($viaState->status)->toBe(EnrolmentStatus::Revoked)
            ->and($viaState->enrolled_by)->toBe($owner->id);

        $anotherStudent = User::factory()->student()->create();

        // Negative: raw mass-assignment must not set any of these guarded fields
        $viaMassAssignment = Enrolment::create([
            'course_id' => $course->id,
            'user_id' => $anotherStudent->id,
            'starts_at' => now(),
            'status' => EnrolmentStatus::Revoked->value,
            'enrolled_by' => $owner->id,
            'revoked_at' => now(),
            'revoked_by' => $owner->id,
        ]);

        expect($viaMassAssignment->status)->toBe(EnrolmentStatus::Active)
            ->and($viaMassAssignment->enrolled_by)->toBeNull()
            ->and($viaMassAssignment->revoked_at)->toBeNull()
            ->and($viaMassAssignment->revoked_by)->toBeNull();
    });
});

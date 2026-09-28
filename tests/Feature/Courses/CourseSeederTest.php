<?php

use App\Enums\CourseStatus;
use App\Enums\LessonStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\TenantSeeder;

test('the seeder creates a published demo course with chapters, lessons and an enrolled student', function () {
    $this->seed(TenantSeeder::class);

    $tenant = Tenant::query()->where('name', 'Demo Institute')->firstOrFail();

    $result = app(TenantContext::class)->runAs($tenant, function () {
        $course = Course::where('title', 'Physics – Class 11')->firstOrFail();
        $chapters = Chapter::where('course_id', $course->id)->count();
        $lessons = Lesson::where('course_id', $course->id)->get();
        $freePreview = $lessons->where('is_free_preview', true)->first();
        $draftLesson = $lessons->firstWhere('status', LessonStatus::Draft);
        $paidLessons = $lessons->where('is_free_preview', false);

        $student = User::where('email', 'student@demo.coaching.test')->firstOrFail();
        $enrolment = Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first();

        return [
            'course_status' => $course->status,
            'chapters' => $chapters,
            'lesson_count' => $lessons->count(),
            'free_preview_has_video' => $freePreview?->youtube_video_id !== null,
            'has_draft_lesson' => $draftLesson !== null,
            'paid_lesson_count' => $paidLessons->count(),
            'enrolment' => $enrolment,
        ];
    });

    expect($result['course_status'])->toBe(CourseStatus::Published)
        ->and($result['chapters'])->toBe(2)
        ->and($result['lesson_count'])->toBe(4)
        ->and($result['free_preview_has_video'])->toBeTrue()
        ->and($result['has_draft_lesson'])->toBeTrue()
        ->and($result['paid_lesson_count'])->toBe(2)
        ->and($result['enrolment'])->not->toBeNull()
        ->and($result['enrolment']->ends_at)->toBeNull();
});

test('the seeder creates a second draft course', function () {
    $this->seed(TenantSeeder::class);

    $tenant = Tenant::query()->where('name', 'Demo Institute')->firstOrFail();

    $draftCourseCount = app(TenantContext::class)->runAs(
        $tenant,
        fn () => Course::where('status', CourseStatus::Draft)->count()
    );

    expect($draftCourseCount)->toBe(1);
});

test('demo courses are not seeded when environment is production', function () {
    app()->instance('env', 'production');

    app(TenantSeeder::class)->run();

    $tenant = Tenant::query()->where('name', 'Demo Institute')->firstOrFail();

    $courseCount = app(TenantContext::class)->runAs($tenant, fn () => Course::count());

    expect($courseCount)->toBe(0);
});

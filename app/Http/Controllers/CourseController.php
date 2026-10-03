<?php

namespace App\Http\Controllers;

use App\Enums\CourseStatus;
use App\Enums\LessonStatus;
use App\Enums\LiveClassStatus;
use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\LiveClass;
use App\Support\LessonAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CourseController extends Controller
{
    public function index(): View
    {
        $courses = Course::where('status', CourseStatus::Published)
            ->withCount(['lessons' => fn ($query) => $query->where('status', LessonStatus::Published)])
            ->orderBy('title')
            ->paginate(20);

        return view('courses.index', ['courses' => $courses]);
    }

    public function show(Course $course, LessonAccess $access): View
    {
        $user = Auth::guard('tenant')->user();

        if (! $access->courseIsVisible($user, $course)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $course->load('chapters.lessons');

        // Avoid an N+1 lookup of $lesson->course inside LessonAccess for every lesson on
        // the page — they all belong to this same, already-loaded course.
        foreach ($course->chapters as $chapter) {
            foreach ($chapter->lessons as $lesson) {
                $lesson->setRelation('course', $course);
            }
        }

        $progressSummary = null;

        // Only a viewer with a genuinely valid enrolment sees their progress — never
        // owner/staff previewing, never a guest or non-enrolled student browsing a
        // free-preview lesson.
        if ($user !== null && ! $access->isStaffOrOwner($user) && $access->hasValidEnrolment($user, $course)) {
            $publishedLessonIds = $course->chapters
                ->flatMap(fn ($chapter) => $chapter->lessons)
                ->where('status', LessonStatus::Published)
                ->pluck('id');

            $totalLessons = $publishedLessonIds->count();

            $completedLessonIds = LessonProgress::where('course_id', $course->id)
                ->where('user_id', $user->id)
                ->whereNotNull('completed_at')
                ->whereIn('lesson_id', $publishedLessonIds)
                ->pluck('lesson_id');

            $progressSummary = [
                'completed' => $completedLessonIds->count(),
                'total' => $totalLessons,
                'percent' => $totalLessons > 0 ? (int) round($completedLessonIds->count() / $totalLessons * 100) : 0,
                'completedLessonIds' => $completedLessonIds,
            ];
        }

        // The live-classes section (Phase 12): at most ten upcoming and ten
        // recent sessions, feature-flagged off by default — a course with no
        // live classes renders the exact page it always did.
        $upcomingClasses = collect();
        $pastClasses = collect();

        if ((bool) config('coaching.live_classes_enabled')) {
            $upcomingClasses = LiveClass::where('course_id', $course->id)
                ->whereIn('status', [LiveClassStatus::Scheduled->value, LiveClassStatus::Live->value])
                ->orderBy('starts_at')
                ->limit(10)
                ->get();

            $pastClasses = LiveClass::where('course_id', $course->id)
                ->whereIn('status', [LiveClassStatus::Ended->value, LiveClassStatus::Cancelled->value])
                ->orderByDesc('starts_at')
                ->limit(10)
                ->get();
        }

        return view('courses.show', [
            'course' => $course,
            'viewer' => $user,
            'access' => $access,
            'progressSummary' => $progressSummary,
            'upcomingClasses' => $upcomingClasses,
            'pastClasses' => $pastClasses,
        ]);
    }
}

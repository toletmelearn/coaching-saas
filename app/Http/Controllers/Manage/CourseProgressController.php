<?php

namespace App\Http\Controllers\Manage;

use App\Enums\LessonStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CourseProgressController extends Controller
{
    public function index(Course $course, Request $request): View
    {
        Gate::authorize('viewProgress', $course);

        $publishedLessonIds = Lesson::where('course_id', $course->id)
            ->where('status', LessonStatus::Published)
            ->pluck('id');
        $totalLessons = $publishedLessonIds->count();

        // Aggregated once in SQL (grouped by student), never one query per student —
        // see docs/specs/phase-7-progress.md "Teacher table" and the query-count test.
        $progressSub = LessonProgress::query()
            ->selectRaw('user_id, COUNT(CASE WHEN completed_at IS NOT NULL THEN 1 END) as completed_count, MAX(last_activity_at) as last_activity_at')
            ->where('course_id', $course->id)
            ->when($totalLessons > 0, fn ($q) => $q->whereIn('lesson_id', $publishedLessonIds))
            ->groupBy('user_id');

        $query = Enrolment::query()
            ->where('course_id', $course->id)
            ->with('user')
            ->leftJoinSub($progressSub, 'progress_agg', 'progress_agg.user_id', '=', 'enrolments.user_id')
            ->select('enrolments.*', 'progress_agg.completed_count', 'progress_agg.last_activity_at');

        $inactiveDays = (int) config('coaching.inactive_days', 7);
        $filter = $request->query('filter');

        if ($filter === 'inactive_7d') {
            $query->where(function ($inner) use ($inactiveDays) {
                $inner->whereNull('progress_agg.last_activity_at')
                    ->orWhere('progress_agg.last_activity_at', '<', now()->subDays($inactiveDays));
            });
        } elseif ($filter === 'not_started') {
            $query->whereNull('progress_agg.last_activity_at');
        }

        $sort = $request->query('sort');

        match ($sort) {
            'progress' => $query->orderByDesc('progress_agg.completed_count'),
            'last_active' => $query->orderByDesc('progress_agg.last_activity_at'),
            default => $query->orderByDesc('enrolments.created_at'),
        };

        $enrolments = $query->paginate(25)->withQueryString();

        return view('manage.courses.progress.index', [
            'course' => $course,
            'enrolments' => $enrolments,
            'totalLessons' => $totalLessons,
            'filter' => $filter,
            'sort' => $sort,
            'inactiveDays' => $inactiveDays,
        ]);
    }

    public function show(Course $course, User $user): View
    {
        Gate::authorize('viewProgress', $course);

        $enrolment = Enrolment::where('course_id', $course->id)->where('user_id', $user->id)->firstOrFail();

        $lessons = Lesson::orderedPublishedForCourse($course);

        $progressByLesson = LessonProgress::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('lesson_id');

        return view('manage.courses.progress.show', [
            'course' => $course,
            'student' => $user,
            'enrolment' => $enrolment,
            'lessons' => $lessons,
            'progressByLesson' => $progressByLesson,
        ]);
    }
}

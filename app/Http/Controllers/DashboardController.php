<?php

namespace App\Http\Controllers;

use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Support\LessonAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function show(LessonAccess $access): View
    {
        $user = Auth::guard('tenant')->user();

        if ($user->role->canManageUsers()) {
            return view('dashboard.staff', ['user' => $user]);
        }

        $enrolments = Enrolment::where('user_id', $user->id)->with('course')->get();
        $active = $enrolments->filter(fn (Enrolment $enrolment) => $enrolment->isValidNow());
        $ended = $enrolments->reject(fn (Enrolment $enrolment) => $enrolment->isValidNow());

        $completedLessonIds = LessonProgress::where('user_id', $user->id)
            ->whereIn('course_id', $active->pluck('course_id'))
            ->whereNotNull('completed_at')
            ->pluck('lesson_id')
            ->flip();

        // For each active enrolment: the first lesson the student can open that they
        // haven't completed yet (never a completed lesson — Continue always moves
        // forward), and a completed/total tally for the course's progress bar.
        $courseProgress = $active->mapWithKeys(function (Enrolment $enrolment) use ($user, $access, $completedLessonIds) {
            $orderedLessons = Lesson::orderedPublishedForCourse($enrolment->course);

            $nextLesson = $orderedLessons->first(
                fn (Lesson $lesson) => $access->lessonAccess($user, $lesson) === 'ok' && ! $completedLessonIds->has($lesson->id)
            );

            $total = $orderedLessons->count();
            $completed = $orderedLessons->filter(fn (Lesson $lesson) => $completedLessonIds->has($lesson->id))->count();

            return [$enrolment->course_id => [
                'nextLesson' => $nextLesson,
                'total' => $total,
                'completed' => $completed,
                'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
                'allCompleted' => $total > 0 && $completed >= $total,
            ]];
        });

        return view('dashboard.student', [
            'user' => $user,
            'active' => $active,
            'ended' => $ended,
            'courseProgress' => $courseProgress,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Support\LessonAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LessonController extends Controller
{
    public function show(Course $course, Lesson $lesson, LessonAccess $access): View|Response
    {
        $user = Auth::guard('tenant')->user();

        $result = $access->lessonAccess($user, $lesson);

        if ($result === 'not_found') {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($result === 'redirect_login') {
            return redirect('/login');
        }

        $enrolment = $user !== null ? $access->findEnrolment($user, $course) : null;

        if ($result === 'forbidden') {
            return response()->view('lessons.forbidden', [
                'course' => $course,
                'lesson' => $lesson,
                'enrolment' => $enrolment,
            ], Response::HTTP_FORBIDDEN);
        }

        $lesson->load('attachments');

        [$previous, $next] = $this->siblingLessons($course, $lesson);

        return view('lessons.show', [
            'course' => $course,
            'lesson' => $lesson,
            'previous' => $previous,
            'next' => $next,
        ]);
    }

    /**
     * Previous/next lesson in the course's chapter/position order, skipping drafts —
     * paid lessons the viewer can't open yet are still included, so Next can lead them to
     * the normal access check (guest -> login redirect, non-enrolled -> 403) rather than
     * silently disappearing.
     *
     * @return array{0: ?Lesson, 1: ?Lesson}
     */
    private function siblingLessons(Course $course, Lesson $lesson): array
    {
        $orderedLessons = Lesson::orderedPublishedForCourse($course);

        $index = $orderedLessons->search(fn (Lesson $candidate) => $candidate->id === $lesson->id);

        if ($index === false) {
            return [null, null];
        }

        return [
            $orderedLessons->get($index - 1),
            $orderedLessons->get($index + 1),
        ];
    }
}

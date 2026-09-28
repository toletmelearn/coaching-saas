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

        return view('lessons.show', [
            'course' => $course,
            'lesson' => $lesson,
        ]);
    }
}

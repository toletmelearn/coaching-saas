<?php

namespace App\Http\Controllers;

use App\Models\Enrolment;
use App\Models\Lesson;
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

        $continueLessons = $active->mapWithKeys(function (Enrolment $enrolment) use ($user, $access) {
            $firstOpenable = Lesson::orderedPublishedForCourse($enrolment->course)
                ->first(fn (Lesson $lesson) => $access->lessonAccess($user, $lesson) === 'ok');

            return [$enrolment->course_id => $firstOpenable];
        });

        return view('dashboard.student', [
            'user' => $user,
            'active' => $active,
            'ended' => $ended,
            'continueLessons' => $continueLessons,
        ]);
    }
}

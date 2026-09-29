<?php

namespace App\Http\Controllers;

use App\Enums\CourseStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
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

        return view('courses.show', ['course' => $course]);
    }
}

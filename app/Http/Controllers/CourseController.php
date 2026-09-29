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

        // Avoid an N+1 lookup of $lesson->course inside LessonAccess for every lesson on
        // the page — they all belong to this same, already-loaded course.
        foreach ($course->chapters as $chapter) {
            foreach ($chapter->lessons as $lesson) {
                $lesson->setRelation('course', $course);
            }
        }

        return view('courses.show', ['course' => $course, 'viewer' => $user, 'access' => $access]);
    }
}

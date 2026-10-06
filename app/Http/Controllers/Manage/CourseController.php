<?php

namespace App\Http\Controllers\Manage;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Course::class);

        $courses = Course::orderBy('title')->paginate(20);

        return view('manage.courses.index', ['courses' => $courses]);
    }

    public function create(): View
    {
        Gate::authorize('create', Course::class);

        return view('manage.courses.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Course::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170'],
            'description' => ['nullable', 'string'],
            'class_level' => ['nullable', 'string', 'max:20'],
            'subject' => ['nullable', 'string', 'max:60'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'enrolment_duration' => ['nullable', Rule::in(Course::DURATIONS)],
        ]);

        $fee = $data['fee'] ?? null;
        unset($data['fee']);
        $data['fee_paise'] = ($fee !== null) ? (int) round((float) $fee * 100) : null;

        $course = new Course($data);
        $course->forceFill(['created_by' => $request->user('tenant')->id]);
        $course->save();

        return redirect("/manage/courses/{$course->id}");
    }

    public function edit(Course $course): View
    {
        Gate::authorize('view', $course);

        $course->load('chapters.lessons');

        return view('manage.courses.edit', ['course' => $course]);
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        Gate::authorize('update', $course);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:170'],
            'description' => ['nullable', 'string'],
            'class_level' => ['nullable', 'string', 'max:20'],
            'subject' => ['nullable', 'string', 'max:60'],
            'fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'enrolment_duration' => ['sometimes', 'nullable', Rule::in(Course::DURATIONS)],
        ]);

        if (array_key_exists('fee', $data)) {
            $fee = $data['fee'];
            unset($data['fee']);
            $data['fee_paise'] = ($fee !== null) ? (int) round((float) $fee * 100) : null;
        }

        $course->update($data);

        return redirect("/manage/courses/{$course->id}");
    }

    public function publish(Course $course): RedirectResponse
    {
        Gate::authorize('publish', $course);

        $course->forceFill(['status' => CourseStatus::Published, 'published_at' => now()])->save();

        return redirect("/manage/courses/{$course->id}");
    }

    public function unpublish(Course $course): RedirectResponse
    {
        Gate::authorize('publish', $course);

        $course->forceFill(['status' => CourseStatus::Draft])->save();

        return redirect("/manage/courses/{$course->id}");
    }

    public function archive(Course $course): RedirectResponse
    {
        Gate::authorize('archive', $course);

        $course->forceFill(['status' => CourseStatus::Archived])->save();

        return redirect("/manage/courses/{$course->id}");
    }
}

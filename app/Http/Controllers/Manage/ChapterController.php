<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChapterController extends Controller
{
    public function store(Request $request, Course $course): RedirectResponse
    {
        Gate::authorize('manageContent', $course);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
        ]);

        $course->chapters()->create($data);

        return redirect("/manage/courses/{$course->id}");
    }

    public function destroy(Chapter $chapter): RedirectResponse
    {
        Gate::authorize('manageContent', $chapter->course);

        if ($chapter->lessons()->exists()) {
            throw ValidationException::withMessages([
                'chapter' => __('courses.manage.chapter_has_lessons'),
            ]);
        }

        $courseId = $chapter->course_id;
        $chapter->delete();

        return redirect("/manage/courses/{$courseId}");
    }

    public function moveUp(Chapter $chapter): RedirectResponse
    {
        Gate::authorize('manageContent', $chapter->course);

        $sibling = Chapter::where('course_id', $chapter->course_id)
            ->where('position', '<', $chapter->position)
            ->orderByDesc('position')
            ->first();

        $this->swapPositions($chapter, $sibling);

        return redirect("/manage/courses/{$chapter->course_id}");
    }

    public function moveDown(Chapter $chapter): RedirectResponse
    {
        Gate::authorize('manageContent', $chapter->course);

        $sibling = Chapter::where('course_id', $chapter->course_id)
            ->where('position', '>', $chapter->position)
            ->orderBy('position')
            ->first();

        $this->swapPositions($chapter, $sibling);

        return redirect("/manage/courses/{$chapter->course_id}");
    }

    private function swapPositions(Chapter $chapter, ?Chapter $sibling): void
    {
        if ($sibling === null) {
            return;
        }

        DB::transaction(function () use ($chapter, $sibling) {
            $chapterPosition = $chapter->position;
            $siblingPosition = $sibling->position;

            $chapter->update(['position' => $siblingPosition]);
            $sibling->update(['position' => $chapterPosition]);
        });
    }
}

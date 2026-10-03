<?php

namespace App\Http\Controllers\Manage;

use App\Enums\LessonStatus;
use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Lesson;
use App\Support\YoutubeUrlParser;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LessonController extends Controller
{
    public function edit(Lesson $lesson): View
    {
        Gate::authorize('manageContent', $lesson->course);

        $lesson->load('attachments');
        $lesson->loadMissing('video');

        return view('manage.lessons.edit', ['lesson' => $lesson]);
    }

    public function store(Request $request, Chapter $chapter): RedirectResponse
    {
        Gate::authorize('manageContent', $chapter->course);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'board_tag' => ['nullable', 'string', 'max:40'],
            'is_free_preview' => ['nullable', 'boolean'],
            'youtube_url' => ['nullable', 'string'],
        ]);

        $isFreePreview = $request->boolean('is_free_preview');
        $videoId = $this->resolveYoutubeVideoId($request->input('youtube_url'), $isFreePreview);

        $lesson = $chapter->lessons()->create([
            'course_id' => $chapter->course_id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'board_tag' => $data['board_tag'] ?? null,
            'is_free_preview' => $isFreePreview,
            'youtube_video_id' => $videoId,
        ]);

        return redirect("/manage/courses/{$lesson->course_id}");
    }

    public function update(Request $request, Lesson $lesson): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'board_tag' => ['nullable', 'string', 'max:40'],
            'is_free_preview' => ['sometimes', 'boolean'],
            'youtube_url' => ['nullable', 'string'],
        ]);

        $isFreePreview = $request->has('is_free_preview') ? $request->boolean('is_free_preview') : $lesson->is_free_preview;

        if (! $isFreePreview && $lesson->youtube_video_id !== null && ! $request->filled('youtube_url')) {
            throw ValidationException::withMessages([
                'is_free_preview' => __('courses.manage.remove_video_first'),
            ]);
        }

        if ($request->filled('youtube_url') && $lesson->video !== null) {
            throw ValidationException::withMessages([
                'youtube_url' => __('lessons.video.lesson_has_video'),
            ]);
        }

        $videoId = $lesson->youtube_video_id;

        if ($request->filled('youtube_url')) {
            $videoId = $this->resolveYoutubeVideoId($request->input('youtube_url'), $isFreePreview);
        } elseif (! $isFreePreview) {
            $videoId = null;
        }

        $lesson->fill($data);
        $lesson->forceFill([
            'is_free_preview' => $isFreePreview,
            'youtube_video_id' => $videoId,
        ]);
        $lesson->save();

        return redirect("/manage/courses/{$lesson->course_id}");
    }

    public function publish(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $lesson->forceFill(['status' => LessonStatus::Published, 'published_at' => now()])->save();

        return redirect("/manage/courses/{$lesson->course_id}");
    }

    public function unpublish(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $lesson->forceFill(['status' => LessonStatus::Draft])->save();

        return redirect("/manage/courses/{$lesson->course_id}");
    }

    public function destroy(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $courseId = $lesson->course_id;
        $attachments = $lesson->attachments()->get();

        try {
            DB::transaction(function () use ($lesson) {
                $lesson->delete();
            });
        } catch (QueryException $e) {
            // The composite FK from live_classes.lesson_id restricts this delete
            // (Phase 12): a linked live class must be unlinked first so a session
            // record can never vanish silently. SQLSTATE 23000 = integrity
            // violation; anything else is a real failure and gets rethrown.
            $sqlState = $e->errorInfo[0] ?? '';

            if ($sqlState !== '23000') {
                throw $e;
            }

            return redirect("/manage/lessons/{$lesson->id}/edit")
                ->withErrors(['lesson' => __('live_classes.lesson_in_use')]);
        }

        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }

        return redirect("/manage/courses/{$courseId}");
    }

    public function moveUp(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $sibling = Lesson::where('chapter_id', $lesson->chapter_id)
            ->where('position', '<', $lesson->position)
            ->orderByDesc('position')
            ->first();

        $this->swapPositions($lesson, $sibling);

        return redirect("/manage/courses/{$lesson->course_id}");
    }

    public function moveDown(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $sibling = Lesson::where('chapter_id', $lesson->chapter_id)
            ->where('position', '>', $lesson->position)
            ->orderBy('position')
            ->first();

        $this->swapPositions($lesson, $sibling);

        return redirect("/manage/courses/{$lesson->course_id}");
    }

    private function swapPositions(Lesson $lesson, ?Lesson $sibling): void
    {
        if ($sibling === null) {
            return;
        }

        DB::transaction(function () use ($lesson, $sibling) {
            $lessonPosition = $lesson->position;
            $siblingPosition = $sibling->position;

            $lesson->update(['position' => $siblingPosition]);
            $sibling->update(['position' => $lessonPosition]);
        });
    }

    private function resolveYoutubeVideoId(?string $url, bool $isFreePreview): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (! $isFreePreview) {
            throw ValidationException::withMessages([
                'youtube_url' => __('courses.manage.video_free_preview_only'),
            ]);
        }

        $videoId = YoutubeUrlParser::parseVideoId($url);

        if ($videoId === null) {
            throw ValidationException::withMessages([
                'youtube_url' => __('courses.manage.invalid_youtube_url'),
            ]);
        }

        return $videoId;
    }
}

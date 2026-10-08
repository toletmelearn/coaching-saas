<?php

namespace App\Http\Controllers\Manage;

use App\Enums\LiveClassStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LiveClass;
use App\Rules\MeetingUrl;
use App\Support\LiveClasses\IstDateTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Teacher-side live-class scheduling (Phase 12).
 *
 * Every action is gated by CoursePolicy::manageLiveClasses (staff/owner only)
 * and by the feature flag first — with LIVE_CLASSES_ENABLED=false the whole
 * surface 404s as if it had never shipped. Server-owned columns (tenant_id,
 * status, jitsi_room_name, created_by) are never taken from the request: the
 * validated array carries only what a teacher may actually decide.
 */
class LiveClassController extends Controller
{
    public function index(Course $course): View
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        $all = LiveClass::where('course_id', $course->id)->orderBy('starts_at')->get();

        return view('manage.live-classes.index', [
            'course' => $course,
            'upcoming' => $all->filter(fn (LiveClass $class) => in_array($class->status, [LiveClassStatus::Scheduled, LiveClassStatus::Live], true))->values(),
            'past' => $all->filter(fn (LiveClass $class) => in_array($class->status, [LiveClassStatus::Ended, LiveClassStatus::Cancelled], true))->values(),
        ]);
    }

    public function create(Course $course): View
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        return view('manage.live-classes.create', [
            'course' => $course,
            'lessons' => $this->courseLessons($course),
        ]);
    }

    public function store(Request $request, Course $course): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        // datetime-local inputs carry no timezone — interpret as IST wall time and
        // convert to UTC before validation so after:now compares UTC against UTC.
        $this->convertIstInputs($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'lesson_id' => $this->lessonRule($course),
            'meeting_url' => ['nullable', 'string', new MeetingUrl],
        ]);

        $class = new LiveClass;
        $class->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'lesson_id' => $data['lesson_id'] ?? null,
            'meeting_url' => $data['meeting_url'] ?? null,
        ]);
        // Server-owned: the route's course and the authenticated teacher —
        // a posted jitsi_room_name/status/tenant_id/created_by never reaches fill().
        $class->forceFill([
            'course_id' => $course->id,
            'created_by' => $request->user('tenant')->id,
        ]);
        $class->save();

        return redirect("/manage/courses/{$course->id}/live-classes");
    }

    public function edit(Course $course, LiveClass $liveClass): View
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        return view('manage.live-classes.edit', [
            'course' => $course,
            'liveClass' => $liveClass,
            'lessons' => $this->courseLessons($course),
        ]);
    }

    public function update(Request $request, Course $course, LiveClass $liveClass): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        $editUrl = "/manage/courses/{$course->id}/live-classes/{$liveClass->id}/edit";

        // Cancelled is terminal: the row keeps its history (status, title, who
        // scheduled it) instead of becoming editable fiction.
        if ($liveClass->status === LiveClassStatus::Cancelled) {
            return redirect($editUrl)
                ->withErrors(['live_class' => __('live_classes.cannot_edit_cancelled')]);
        }

        $this->convertIstInputs($request);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'lesson_id' => $this->lessonRule($course),
            'meeting_url' => ['nullable', 'string', new MeetingUrl],
        ]);

        $liveClass->fill($data);
        $liveClass->save();

        return redirect($editUrl);
    }

    public function cancel(Course $course, LiveClass $liveClass): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        $liveClass->forceFill(['status' => LiveClassStatus::Cancelled])->save();

        return redirect("/manage/courses/{$course->id}/live-classes");
    }

    public function destroy(Course $course, LiveClass $liveClass): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        $liveClass->delete();

        return redirect("/manage/courses/{$course->id}/live-classes");
    }

    /**
     * Rewrite datetime-local values (bare "Y-m-d\TH:i" strings) back into the
     * request as UTC strings. The form inputs carry IST wall time; if we leave
     * them as-is, Carbon/validation would interpret them as UTC (the app timezone),
     * off by 5h 30m from the intended instant.
     */
    private function convertIstInputs(Request $request): void
    {
        $overrides = [];

        foreach (['starts_at', 'ends_at'] as $field) {
            $raw = $request->input($field);
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $utc = IstDateTime::fromInput($raw);
            if ($utc !== null) {
                $overrides[$field] = $utc;
            }
        }

        if ($overrides !== []) {
            $request->merge($overrides);
        }
    }

    /**
     * A lesson may only be linked when it belongs to *this* course (and so,
     * through the composite FK, this tenant) — a foreign lesson id is a
     * validation error on lesson_id, never a stored cross-course link.
     */
    private function lessonRule(Course $course): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('lessons', 'id')
                ->where('course_id', $course->id)
                ->where('tenant_id', $course->tenant_id),
        ];
    }

    private function courseLessons(Course $course): array
    {
        // Explicit tenant_id filter mirrors lessonRule() — defence-in-depth
        // alongside the BelongsToTenant global scope.
        return Lesson::where('course_id', $course->id)
            ->where('tenant_id', $course->tenant_id)
            ->orderBy('title')
            ->get(['id', 'title'])
            ->toArray();
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('coaching.live_classes_enabled'), Response::HTTP_NOT_FOUND);
    }
}

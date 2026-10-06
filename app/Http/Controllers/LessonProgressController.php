<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Support\Access\ContentAccessGate;
use App\Support\LessonAccess;
use App\Support\ProgressRecorder;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LessonProgressController extends Controller
{
    public function heartbeat(Request $request, Lesson $lesson, LessonAccess $access, ContentAccessGate $gate, ProgressRecorder $recorder): JsonResponse|Response
    {
        /** @var ?User $user */
        $user = $request->user('tenant');

        if ($access->isStaffOrOwner($user)) {
            return response()->noContent();
        }

        abort_if($user === null, Response::HTTP_UNAUTHORIZED);

        $this->authorizeRecordable($access, $gate, $user, $lesson);
        $this->throttle('heartbeat', $lesson, $user);

        $data = $request->validate([
            'position' => ['required', 'numeric', 'min:0', 'max:86400'],
            'duration' => ['required', 'numeric', 'min:1', 'max:86400'],
            'played' => ['required', 'numeric', 'min:0', 'max:120'],
            'ended' => ['sometimes', 'boolean'],
        ]);

        $progress = DB::transaction(function () use ($lesson, $user, $recorder, $data) {
            $progress = $this->lockOrCreateProgressRow($lesson, $lesson->course_id, $user->id);

            $updated = $recorder->applyHeartbeat($this->stateFromModel($progress), $data, now());
            $progress->forceFill($updated)->save();

            return $progress;
        });

        return response()->json([
            'watched_seconds' => $progress->watched_seconds,
            'completed' => $progress->isCompleted(),
        ]);
    }

    public function completion(Request $request, Lesson $lesson, LessonAccess $access, ContentAccessGate $gate): JsonResponse|Response
    {
        /** @var ?User $user */
        $user = $request->user('tenant');

        if ($access->isStaffOrOwner($user)) {
            return response()->noContent();
        }

        abort_if($user === null, Response::HTTP_UNAUTHORIZED);

        $this->authorizeRecordable($access, $gate, $user, $lesson);
        $this->throttle('completion', $lesson, $user);

        $data = $request->validate([
            'completed' => ['required', 'boolean'],
        ]);

        $lesson->loadMissing('video');
        $hasReadyProtectedVideo = $lesson->video !== null && $lesson->video->status === VideoStatus::Ready;

        if ($data['completed'] && $hasReadyProtectedVideo) {
            throw ValidationException::withMessages([
                'completed' => __('lessons.progress.cannot_mark_video_lesson'),
            ]);
        }

        $progress = DB::transaction(function () use ($lesson, $user, $data) {
            $progress = $this->lockOrCreateProgressRow($lesson, $lesson->course_id, $user->id);

            if ($data['completed']) {
                $progress->forceFill(['completed_at' => now(), 'completed_manually' => true])->save();
            } else {
                if (! $progress->completed_manually) {
                    throw ValidationException::withMessages([
                        'completed' => __('lessons.progress.cannot_unmark'),
                    ]);
                }

                $progress->forceFill(['completed_at' => null, 'completed_manually' => false])->save();
            }

            return $progress;
        });

        if (! $request->expectsJson()) {
            return redirect()->back();
        }

        return response()->json([
            'watched_seconds' => $progress->watched_seconds,
            'completed' => $progress->isCompleted(),
        ]);
    }

    private function authorizeRecordable(LessonAccess $access, ContentAccessGate $gate, User $user, Lesson $lesson): void
    {
        $result = $gate->lessonState($user, $lesson);

        if ($result === 'not_found') {
            abort(Response::HTTP_NOT_FOUND);
        }

        // payment_needed and consent_withdrawn refuse progress just as forbidden does.
        if ($result !== 'ok') {
            abort(Response::HTTP_FORBIDDEN);
        }

        // LessonAccess::lessonAccess() returns 'ok' for a free-preview lesson even
        // without an enrolment (anyone can watch the preview) — but progress is only
        // ever recorded for a student with a genuinely valid enrolment (see
        // docs/specs/phase-7-progress.md, "Product decisions" #1).
        if (! $access->hasValidEnrolment($user, $lesson->course)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }

    private function throttle(string $action, Lesson $lesson, User $user): void
    {
        $key = sprintf('lesson-progress-%s:%d:%d:%d', $action, $lesson->tenant_id, $user->id, $lesson->id);
        $maxAttempts = (int) config('coaching.progress_heartbeat_max_attempts', 12);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            abort(Response::HTTP_TOO_MANY_REQUESTS);
        }

        RateLimiter::hit($key, (int) config('coaching.progress_heartbeat_decay_seconds', 60));
    }

    /**
     * Loads and row-locks the student's progress row for this lesson, creating it
     * first if needed. Callers run this inside their own DB::transaction() so the
     * lock is held for the entire read-modify-write — two concurrent heartbeats for
     * the same (tenant, lesson, user) never race each other's update.
     */
    private function lockOrCreateProgressRow(Lesson $lesson, int $courseId, int $userId): LessonProgress
    {
        $progress = LessonProgress::where('lesson_id', $lesson->id)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($progress !== null) {
            return $progress;
        }

        try {
            LessonProgress::create([
                'lesson_id' => $lesson->id,
                'course_id' => $courseId,
                'user_id' => $userId,
            ]);
        } catch (QueryException) {
            // Lost the create race to a concurrent request within the same DB
            // transaction retry — fall through to the lock below, which will now
            // find the row the other request created.
        }

        return LessonProgress::where('lesson_id', $lesson->id)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array{watched_seconds:int,duration_seconds:?int,last_position_seconds:int,completed_at:?Carbon,completed_manually:bool,last_heartbeat_at:?Carbon,last_activity_at:?Carbon}
     */
    private function stateFromModel(LessonProgress $progress): array
    {
        return [
            'watched_seconds' => $progress->watched_seconds,
            'duration_seconds' => $progress->duration_seconds,
            'last_position_seconds' => $progress->last_position_seconds,
            'completed_at' => $progress->completed_at,
            'completed_manually' => $progress->completed_manually,
            'last_heartbeat_at' => $progress->last_heartbeat_at,
            'last_activity_at' => $progress->last_activity_at,
        ];
    }
}

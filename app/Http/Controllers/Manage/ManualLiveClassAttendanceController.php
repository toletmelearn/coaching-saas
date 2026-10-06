<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Course;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manual attendance mark/unmark for owner/staff (Batch 2.3).
 *
 * POST   …/attendance/{user}/mark  — writes a live_class_attendances row with
 *                                     joined_at = now() if none is open; no-op
 *                                     when a heartbeat row already exists.
 * DELETE …/attendance/{user}/mark  — closes all open rows (sets left_at = now())
 *                                     for the given student in this class.
 *
 * Both actions write an admin_audit_logs row with action = 'attendance_manual'.
 * Neither action is available to students (manageLiveClasses gate: owner/staff).
 */
class ManualLiveClassAttendanceController extends Controller
{
    public function mark(Request $request, Course $course, LiveClass $liveClass, User $user): JsonResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);
        abort_unless($liveClass->course_id === $course->id, 404);

        $open = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if ($open === null) {
            $row = new LiveClassAttendance;
            $row->forceFill([
                'live_class_id' => $liveClass->id,
                'user_id' => $user->id,
                'joined_at' => now(),
                'last_seen_at' => now(),
                'duration_seconds' => 0,
            ])->save();
        }

        AdminAuditLog::record(
            action: 'attendance_manual',
            targetType: 'LiveClass',
            targetId: $liveClass->id,
        );

        return response()->json(['marked' => true]);
    }

    public function unmark(Request $request, Course $course, LiveClass $liveClass, User $user): JsonResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);
        abort_unless($liveClass->course_id === $course->id, 404);

        LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);

        AdminAuditLog::record(
            action: 'attendance_manual',
            targetType: 'LiveClass',
            targetId: $liveClass->id,
        );

        return response()->json(['marked' => false]);
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('coaching.live_classes_enabled'), Response::HTTP_NOT_FOUND);
    }
}

<?php

namespace App\Policies;

use App\Enums\LiveClassStatus;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\Access\ContentAccessGate;
use App\Support\LessonAccess;

/**
 * Who may see and enter a live class (Phase 12).
 *
 * view  — owner/staff, or a student with a valid enrolment in the class's
 *         course. Everything student-facing (show, join, heartbeat) starts here.
 * join  — view() plus "the class is actually open for business": live, or
 *         scheduled within the configured pre-join window. Heartbeat calls this
 *         and answers flat 403 for every refusal; the join page instead turns
 *         the same rules into a friendly flash message, so the reason text
 *         lives in lang/en/live_classes.php, not here.
 */
class LiveClassPolicy
{
    public function __construct(private LessonAccess $access, private ContentAccessGate $gate) {}

    /**
     * Staff always; a student needs a valid enrolment and the course's payment and consent rules
     * (ContentAccessGate) to be satisfied, so an unpaid or consent-withdrawn student gets no room.
     */
    public function view(User $actor, LiveClass $liveClass): bool
    {
        if ($this->access->isStaffOrOwner($actor)) {
            return true;
        }

        return $this->access->hasValidEnrolment($actor, $liveClass->course)
            && $this->gate->courseContentState($actor, $liveClass->course) === 'ok';
    }

    public function join(User $actor, LiveClass $liveClass): bool
    {
        if (! $this->view($actor, $liveClass)) {
            return false;
        }

        return match ($liveClass->status) {
            LiveClassStatus::Live => true,
            LiveClassStatus::Scheduled => $liveClass->starts_at->lte(
                now()->addMinutes((int) config('coaching.live_class_join_window_minutes', 15))
            ),
            LiveClassStatus::Ended, LiveClassStatus::Cancelled => false,
        };
    }
}

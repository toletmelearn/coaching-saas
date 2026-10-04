<?php

namespace App\Support;

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use App\Models\User;

/**
 * Consent-based access checks (Phase 15), kept out of LessonAccess on purpose —
 * LessonAccess is a locked file, and the DPDP withdrawal block is a separate
 * concern layered at the controller level.
 *
 * Semantics (per the Phase 15 scope): a student who never had a consent row is
 * NOT blocked — blocking on *missing* consent is explicitly out of scope for
 * this phase, and every pre-Phase 15 student has no rows at all. Only a student
 * whose latest course_delivery grant carries a withdrawn_at is refused.
 */
class ConsentAccess
{
    /**
     * Whether the student's most recent course_delivery consent was withdrawn.
     *
     * "Most recent" wins so that a withdrawal followed by a later re-grant
     * unlocks the course again (consents are unique per grant time, so a
     * purpose may legitimately be granted more than once).
     */
    public static function courseDeliveryWithdrawn(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $latest = Consent::query()
            ->where('user_id', $user->id)
            ->where('purpose', ConsentPurpose::CourseDelivery->value)
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->first();

        return $latest !== null && $latest->withdrawn_at !== null;
    }
}

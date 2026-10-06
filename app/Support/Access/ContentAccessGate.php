<?php

namespace App\Support\Access;

use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\User;
use App\Support\ConsentAccess;
use App\Support\LessonAccess;
use App\Support\Payments\PaymentStateResolver;

/**
 * The one answer to "may this user see this paid content?" (Batch 1 B). Every content path calls
 * this rather than re-deriving the rules: the lesson page, attachment download, video stream,
 * progress heartbeat, live-class view and join, and the dashboard "Continue" link.
 *
 * It layers the payment and consent rules on top of LessonAccess, which is left untouched (locked).
 * Staff and owners always pass. A free course (fee 0) and a free-preview lesson keep their rules.
 *
 * States: 'ok', plus the LessonAccess results ('not_found', 'redirect_login', 'forbidden'),
 * 'payment_needed' (enrolled, fee due, payment not approved) and 'consent_withdrawn'.
 */
class ContentAccessGate
{
    public function __construct(private LessonAccess $access) {}

    public function lessonState(?User $user, Lesson $lesson): string
    {
        $base = $this->access->lessonAccess($user, $lesson);

        if ($base !== 'ok' || $user === null || $this->access->isStaffOrOwner($user)) {
            return $base;
        }

        if (ConsentAccess::courseDeliveryWithdrawn($user)) {
            return 'consent_withdrawn';
        }

        $enrolment = $this->access->findEnrolment($user, $lesson->course);

        // A free-preview lesson opened by someone with no enrolment is public: nothing further applies.
        if ($enrolment === null || ! $enrolment->isValidNow()) {
            return 'ok';
        }

        return $this->paymentCleared($enrolment, $lesson->course) ? 'ok' : 'payment_needed';
    }

    /**
     * Course-level answer for callers that have no single lesson (the live-class view and join).
     * Enrolment validity is the caller's check; this covers the payment and consent rules.
     */
    public function courseContentState(User $user, Course $course): string
    {
        if ($this->access->isStaffOrOwner($user)) {
            return 'ok';
        }

        if (ConsentAccess::courseDeliveryWithdrawn($user)) {
            return 'consent_withdrawn';
        }

        $enrolment = $this->access->findEnrolment($user, $course);

        if ($enrolment === null) {
            return 'ok';
        }

        return $this->paymentCleared($enrolment, $course) ? 'ok' : 'payment_needed';
    }

    private function paymentCleared(Enrolment $enrolment, Course $course): bool
    {
        if (($course->fee_paise ?? 0) <= 0) {
            return true;
        }

        return PaymentStateResolver::forEnrolment($enrolment)['state'] === PaymentStateResolver::APPROVED;
    }
}

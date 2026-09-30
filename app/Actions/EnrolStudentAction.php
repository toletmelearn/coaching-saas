<?php

namespace App\Actions;

use App\Enums\EnrolmentStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The single place that creates or reactivates an Enrolment — used by both the manual
 * enrolment screen (Manage\EnrolmentController::store) and bulk CSV import, so the two
 * paths can never drift. Already-active is a no-op (returns the existing row unchanged);
 * a revoked/expired row is reactivated in place rather than duplicated.
 */
class EnrolStudentAction
{
    public function __invoke(Course $course, User $student, Carbon $startsAt, ?Carbon $endsAt, ?string $paymentNote, int $enrolledBy): Enrolment
    {
        $existing = Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first();

        if ($existing !== null && $existing->status === EnrolmentStatus::Active) {
            return $existing;
        }

        if ($existing !== null) {
            $existing->forceFill([
                'status' => EnrolmentStatus::Active,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'payment_note' => $paymentNote,
                'revoked_at' => null,
                'revoked_by' => null,
            ])->save();

            return $existing;
        }

        $enrolment = new Enrolment([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'payment_note' => $paymentNote,
        ]);
        $enrolment->forceFill(['enrolled_by' => $enrolledBy]);
        $enrolment->save();

        return $enrolment;
    }
}

<?php

namespace App\Support\Payments;

use App\Models\Enrolment;

/**
 * Resolves what a payment for a given enrolment actually costs.
 *
 * The price is always read off the course record on the server. Nothing a client
 * posts is ever consulted — an amount_paise, price or fee_paise field in the
 * request body has no path into this class, which is what makes the posted
 * amount_paise-is-ignored test meaningful rather than incidental.
 */
class AmountResolver
{
    /**
     * The fee in paise, or 0 when the course has never been given one — callers
     * treat 0 as "not chargeable yet" rather than "free", because a manual UPI
     * handoff needs an institute-defined figure before it can start.
     */
    public static function forEnrolment(Enrolment $enrolment): int
    {
        $fee = $enrolment->course?->fee_paise;

        return $fee === null ? 0 : (int) $fee;
    }
}

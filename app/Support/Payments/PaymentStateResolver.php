<?php

namespace App\Support\Payments;

use App\Enums\PaymentStatus;
use App\Models\Enrolment;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides what a student still owes (or has settled) for one enrolment.
 *
 * Three states drive both the dashboard status card and the lesson-page gate:
 *
 *  - approved — some payment for the enrolment reached Approved;
 *  - pending  — a submission is waiting for owner review (no approval yet);
 *  - needed   — nothing submitted, or everything submitted was rejected.
 *
 * The amount always comes from AmountResolver (server-side fee), never from
 * anything a client posted. All queries run through the tenant-scoped Payment
 * model, so a payment of another tenant can never be matched.
 */
class PaymentStateResolver
{
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    public const NEEDED = 'needed';

    /**
     * @return array{state: string, payment: Payment|null, amount: int}
     */
    public static function forEnrolment(Enrolment $enrolment): array
    {
        $payments = Payment::query()
            ->where('enrolment_id', $enrolment->getKey())
            ->orderByDesc('id')
            ->get();

        return self::resolve($enrolment, $payments);
    }

    /**
     * Batched variant for a list of enrolments: one payments query in total,
     * so the dashboard never walks its enrolment list one query at a time.
     *
     * @param  iterable<Enrolment>  $enrolments
     * @return array<int, array{state: string, payment: Payment|null, amount: int}> keyed by enrolment id
     */
    public static function forEnrolments(iterable $enrolments): array
    {
        $list = collect($enrolments)->values();

        if ($list->isEmpty()) {
            return [];
        }

        $paymentsByEnrolment = Payment::query()
            ->whereIn('enrolment_id', $list->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('enrolment_id');

        $states = [];

        foreach ($list as $enrolment) {
            $states[(int) $enrolment->getKey()] = self::resolve(
                $enrolment,
                $paymentsByEnrolment->get($enrolment->getKey()) ?? new Collection
            );
        }

        return $states;
    }

    /**
     * @param  Collection<int, Payment>  $payments  newest first
     * @return array{state: string, payment: Payment|null, amount: int}
     */
    private static function resolve(Enrolment $enrolment, Collection $payments): array
    {
        $amount = AmountResolver::forEnrolment($enrolment);

        $approved = $payments->firstWhere('status', PaymentStatus::Approved);

        if ($approved !== null) {
            return ['state' => self::APPROVED, 'payment' => $approved, 'amount' => $amount];
        }

        $pending = $payments->firstWhere('status', PaymentStatus::Pending);

        if ($pending !== null) {
            return ['state' => self::PENDING, 'payment' => $pending, 'amount' => $amount];
        }

        return ['state' => self::NEEDED, 'payment' => null, 'amount' => $amount];
    }
}

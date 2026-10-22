<?php

use App\Enums\PaymentStatus;
use App\Models\Enrolment;
use App\Models\Payment;
use App\Support\Payments\PaymentStateResolver;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reach the private resolve() method directly so we can supply an in-memory
 * Collection without any database queries.
 *
 * @param  Payment[]  $payments  newest-first, as forEnrolment() would order them
 * @return array{state: string, payment: Payment|null, amount: int}
 */
function psr_resolve(array $payments): array
{
    $enrolment = new Enrolment;
    // course_id is null → BelongsTo shortcuts to null without a query → AmountResolver returns 0

    $method = (new ReflectionClass(PaymentStateResolver::class))->getMethod('resolve');
    $method->setAccessible(true);

    return $method->invoke(null, $enrolment, new Collection($payments));
}

/**
 * Build an in-memory Payment with a given status (no DB insert).
 */
function psr_payment(PaymentStatus $status): Payment
{
    $p = new Payment;
    $p->setRawAttributes(['status' => $status->value]);

    return $p;
}

// ── approved ─────────────────────────────────────────────────────────────────

test('single approved payment returns approved state', function () {
    $payment = psr_payment(PaymentStatus::Approved);

    $result = psr_resolve([$payment]);

    expect($result['state'])->toBe(PaymentStateResolver::APPROVED)
        ->and($result['payment'])->toBe($payment);
});

test('approved payment takes precedence over pending', function () {
    $approved = psr_payment(PaymentStatus::Approved);
    $pending  = psr_payment(PaymentStatus::Pending);

    // newest first — pending was submitted after approved (unusual but possible)
    $result = psr_resolve([$pending, $approved]);

    expect($result['state'])->toBe(PaymentStateResolver::APPROVED)
        ->and($result['payment'])->toBe($approved);
});

// ── pending ──────────────────────────────────────────────────────────────────

test('single pending payment returns pending state', function () {
    $payment = psr_payment(PaymentStatus::Pending);

    $result = psr_resolve([$payment]);

    expect($result['state'])->toBe(PaymentStateResolver::PENDING)
        ->and($result['payment'])->toBe($payment);
});

test('rejected plus pending returns pending (rejected does not block)', function () {
    $rejected = psr_payment(PaymentStatus::Rejected);
    $pending  = psr_payment(PaymentStatus::Pending);

    $result = psr_resolve([$pending, $rejected]);

    expect($result['state'])->toBe(PaymentStateResolver::PENDING);
});

// ── needed ───────────────────────────────────────────────────────────────────

test('empty collection returns needed state with null payment', function () {
    $result = psr_resolve([]);

    expect($result['state'])->toBe(PaymentStateResolver::NEEDED)
        ->and($result['payment'])->toBeNull();
});

test('only rejected payments returns needed state', function () {
    $result = psr_resolve([
        psr_payment(PaymentStatus::Rejected),
        psr_payment(PaymentStatus::Rejected),
    ]);

    expect($result['state'])->toBe(PaymentStateResolver::NEEDED)
        ->and($result['payment'])->toBeNull();
});

// ── amount passthrough ────────────────────────────────────────────────────────

test('amount is always 0 when enrolment has no course (AmountResolver passthrough)', function () {
    $result = psr_resolve([psr_payment(PaymentStatus::Approved)]);

    expect($result['amount'])->toBe(0);
});

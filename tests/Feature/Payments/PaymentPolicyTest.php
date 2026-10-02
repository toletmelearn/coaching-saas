<?php

use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Tests\Support\PaymentFixtures;

test('viewPaymentQueue and viewPayment are open to owner and staff but not to a student', function () {
    $f = PaymentFixtures::setup();

    // Positive controls: owner and staff both hold the queue
    expect(Gate::forUser($f['owner'])->allows('viewPaymentQueue', Payment::class))->toBeTrue();
    expect(Gate::forUser($f['staff'])->allows('viewPaymentQueue', Payment::class))->toBeTrue();

    // Negative: a student does not
    expect(Gate::forUser($f['student'])->allows('viewPaymentQueue', Payment::class))->toBeFalse();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);

    expect(Gate::forUser($f['owner'])->allows('viewPayment', $payment))->toBeTrue();
    expect(Gate::forUser($f['staff'])->allows('viewPayment', $payment))->toBeTrue();
    expect(Gate::forUser($f['student'])->allows('viewPayment', $payment))->toBeFalse();
});

test('approvePayment and rejectPayment are owner-only', function () {
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);

    // Positive control: the owner holds both
    expect(Gate::forUser($f['owner'])->allows('approvePayment', $payment))->toBeTrue();
    expect(Gate::forUser($f['owner'])->allows('rejectPayment', $payment))->toBeTrue();

    // Negative: staff may look but not decide, and a student may do neither
    expect(Gate::forUser($f['staff'])->allows('approvePayment', $payment))->toBeFalse();
    expect(Gate::forUser($f['staff'])->allows('rejectPayment', $payment))->toBeFalse();
    expect(Gate::forUser($f['student'])->allows('approvePayment', $payment))->toBeFalse();
    expect(Gate::forUser($f['student'])->allows('rejectPayment', $payment))->toBeFalse();
});

test("viewOwnPayment is granted only for a student's own payment", function () {
    $f = PaymentFixtures::setup();

    $other = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);
    $mine = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    $theirs = PaymentFixtures::payment($f['tenant'], $other['enrolment']);

    // Positive control: their own payment
    expect(Gate::forUser($f['student'])->allows('viewOwnPayment', $mine))->toBeTrue();

    // Negative: another student's payment in the very same tenant
    expect(Gate::forUser($f['student'])->allows('viewOwnPayment', $theirs))->toBeFalse();
});

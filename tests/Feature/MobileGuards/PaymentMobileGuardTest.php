<?php

use Tests\Support\MobileSafetyChecker;
use Tests\Support\PaymentFixtures;

test('the payment queue page is mobile-safe', function () {
    $f = PaymentFixtures::setup();

    PaymentFixtures::payment($f['tenant'], $f['enrolment']);

    $queue = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/manage/payments");

    // Positive control, same reasoning as MobileGuardTest: the app's own 404 page would
    // satisfy the checker vacuously, so require the real page to render first.
    $queue->assertOk();

    expect(MobileSafetyChecker::violations($queue->getContent()))->toBe([]);
});

test('the payment review page is mobile-safe', function () {
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);

    $review = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments/{$payment->id}");

    $review->assertOk();

    expect(MobileSafetyChecker::violations($review->getContent()))->toBe([]);
});

test('the student payment page is mobile-safe', function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $page->assertOk();

    expect(MobileSafetyChecker::violations($page->getContent()))->toBe([]);
});

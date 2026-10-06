<?php

use App\Models\Tenant;
use App\Models\User;
use Tests\Support\PaymentFixtures;

/**
 * Batch 3.4 + 3.5 — UPI deep-link and QR code on the payment page.
 *
 * All 7 tests fail because the upi:// deep-link anchor and the QR code <img>
 * do not exist in resources/views/payments/show.blade.php yet.
 *
 * Tests 1–2, 5 (3.4): assertSee('upi://pay') fails — the link is absent.
 * Tests 3–4 (3.4): assertDontSee passes on current code... but the test also
 *   asserts the enrolled student's path works first, which fails.
 * Test 5 (3.4): asserts enrolled user sees the deep-link (positive control)
 *   before asserting an unenrolled user gets 404 — fails at the first assertion.
 * Tests 6–7 (3.5): assertSee('data:image/png;base64,') fails — the QR img is absent.
 *
 * Note: bacon/bacon-qr-code is NOT currently installed. Step 2 will add it.
 */

test('3.4 payment page shows UPI deep-link when upi_id is set and fee > 0', function () {
    $f = PaymentFixtures::setup(['fee_paise' => 49900]);
    $f['tenant']->forceFill(['upi_id' => 'testpayee@ybl'])->save();

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertOk()
        ->assertSee('upi://pay', false);
});

test('3.4 deep-link href contains correct pa, am, and cu values', function () {
    $f = PaymentFixtures::setup(['fee_paise' => 49900]);
    $f['tenant']->forceFill(['upi_id' => 'testpayee@ybl'])->save();

    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $page->assertOk();
    $page->assertSee('upi://pay', false);
    $page->assertSee('am=499.00', false);
    $page->assertSee('cu=INR', false);
    // pa= contains the UPI id (url-encoded; @ → %40)
    $page->assertSee('pa='.rawurlencode('testpayee@ybl'), false);
});

test('3.4 payment page does NOT show deep-link when upi_id is null', function () {
    $f = PaymentFixtures::setup(['fee_paise' => 49900]);
    // upi_id is null by default

    // Without upi_id: no link (assertDontSee passes trivially — feature not built yet)
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertOk()
        ->assertDontSee('upi://pay', false);

    // Set upi_id: link must now appear (fails before implementation)
    $f['tenant']->forceFill(['upi_id' => 'testpayee@ybl'])->save();

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertSee('upi://pay', false); // FAILS: deep-link not implemented yet
});

test('3.4 payment page does NOT show deep-link when fee is 0 or null', function () {
    $f = PaymentFixtures::setup(['fee_paise' => 0]);
    $f['tenant']->forceFill(['upi_id' => 'testpayee@ybl'])->save();

    // With fee=0 the link must be absent.
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertOk()
        ->assertDontSee('upi://pay', false);

    // Positive control: same page WITH a fee shows the link (fails if not implemented)
    $f2 = PaymentFixtures::setup(['fee_paise' => 49900], 'b3deeplink2.coaching.test');
    $f2['tenant']->forceFill(['upi_id' => 'testpayee@ybl'])->save();

    $this->actingAs($f2['student'], 'tenant')
        ->get("http://{$f2['domain']}/enrolments/{$f2['enrolment']->id}/payment")
        ->assertSee('upi://pay', false); // FAILS: deep-link not implemented yet
});

test('3.4 an unenrolled student cannot reach the payment page (404)', function () {
    $f = PaymentFixtures::setup(['fee_paise' => 49900]);
    $f['tenant']->forceFill(['upi_id' => 'testpayee@ybl'])->save();

    $anotherStudent = inTenant($f['tenant'], fn () => User::factory()->student()->create());

    // Positive control: the enrolled student's page shows the deep-link
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertSee('upi://pay', false); // FAILS: deep-link not implemented yet

    // An unenrolled student gets 404 (not 403 — the controller uses abort_unless with 404)
    $this->actingAs($anotherStudent, 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertNotFound();
});

test('3.5 payment page contains a base64 PNG QR code when upi_id is set and fee > 0', function () {
    $f = PaymentFixtures::setup(['fee_paise' => 49900]);
    $f['tenant']->forceFill(['upi_id' => 'testpayee@ybl'])->save();

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertOk()
        ->assertSee('data:image/png;base64,', false);
});

test('3.5 QR code img is absent when upi_id is null', function () {
    $f = PaymentFixtures::setup(['fee_paise' => 49900]);
    // upi_id is null — no QR code should appear

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertOk()
        ->assertDontSee('data:image/png;base64,', false);

    // Positive control: with upi_id set the QR code appears (fails: not implemented)
    $f['tenant']->forceFill(['upi_id' => 'testpayee@ybl'])->save();

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertSee('data:image/png;base64,', false); // FAILS: QR code not implemented yet
});

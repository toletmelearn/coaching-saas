<?php

use App\Models\Payment;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PaymentFixtures;

test('the amount charged comes from the course record and a posted amount_paise is ignored', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);
    $other = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);

    // Positive control: the same submission with nothing tampered stores the course fee
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertRedirect();

    expect((int) inTenant($f['tenant'], fn () => Payment::firstOrFail())->amount_paise)
        ->toBe(PaymentFixtures::FEE_PAISE);

    // Negative: amount_paise (and price) in the body change nothing about what is stored
    $this->actingAs($other['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$other['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
            'amount_paise' => 1,
            'price' => 1,
            'fee_paise' => 1,
        ])
        ->assertRedirect();

    $amounts = inTenant($f['tenant'], fn () => Payment::query()->pluck('amount_paise'));

    expect($amounts->count())->toBe(2);
    expect($amounts->map(fn ($paise) => (int) $paise)->all())->toBe([
        PaymentFixtures::FEE_PAISE,
        PaymentFixtures::FEE_PAISE,
    ]);
});

test('a course with no fee shows the set-a-fee prompt, hides the form and blocks the upload', function () {
    Storage::fake('local');

    // Positive control: with a fee set, the page renders the upload form
    $paid = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $this->actingAs($paid['student'], 'tenant')
        ->get("http://{$paid['domain']}/enrolments/{$paid['enrolment']->id}/payment")
        ->assertOk()
        ->assertSee('name="screenshot"', false);

    // Negative: no fee set → the prompt, no form, and no way to POST past it
    $unpaid = PaymentFixtures::setup([], 'tenant-b.coaching.test');

    $page = $this->actingAs($unpaid['student'], 'tenant')
        ->get("http://{$unpaid['domain']}/enrolments/{$unpaid['enrolment']->id}/payment");

    $page->assertOk();
    $page->assertSee(__('payments.set_fee_first'));
    $page->assertDontSee('name="screenshot"', false);

    $this->actingAs($unpaid['student'], 'tenant')
        ->post("http://{$unpaid['domain']}/enrolments/{$unpaid['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertUnprocessable();
});

test('the owner can set a course fee from the course editor', function () {
    $f = PaymentFixtures::setup();

    // Positive control: the editor renders a fee field
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/courses/{$f['course']->id}")
        ->assertOk()
        ->assertSee('name="fee"', false);

    $this->actingAs($f['owner'], 'tenant')
        ->patch("http://{$f['domain']}/manage/courses/{$f['course']->id}", [
            'title' => $f['course']->title,
            'fee' => 1234.56,
        ])
        ->assertRedirect();

    expect((int) inTenant($f['tenant'], fn () => $f['course']->refresh()->fee_paise))->toBe(123456);
});

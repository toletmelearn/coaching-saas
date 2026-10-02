<?php

use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PaymentFixtures;

test('the student sees that their payment is pending review after uploading', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertRedirect();

    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $page->assertOk();
    $page->assertSee(__('payments.status.pending'));
    $page->assertSee(__('payments.status.waiting'));

    // One pending payment at a time — the form is gone until this one is reviewed
    $page->assertDontSee('name="screenshot"', false);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('after approval the student sees that the payment is approved', function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    PaymentFixtures::payment($f['tenant'], $f['enrolment'], [
        'status' => PaymentStatus::Approved,
        'reviewed_at' => now(),
        'reviewed_by' => $f['owner']->id,
    ]);

    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $page->assertOk();
    $page->assertSee(__('payments.status.approved'));

    // No further submission is possible once a payment has been approved
    $page->assertDontSee('name="screenshot"', false);
});

test('after rejection the student sees the reason and can resubmit', function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    PaymentFixtures::payment($f['tenant'], $f['enrolment'], [
        'status' => PaymentStatus::Rejected,
        'rejection_reason' => 'The screenshot is unreadable',
        'reviewed_at' => now(),
        'reviewed_by' => $f['owner']->id,
    ]);

    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $page->assertOk();
    $page->assertSee(__('payments.status.rejected'));
    $page->assertSee('The screenshot is unreadable');

    // The resubmission form is back
    $page->assertSee('name="screenshot"', false);
});

test('every payment string is rendered through lang/en/payments.php', function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    expect(file_exists(lang_path('en/payments.php')))->toBeTrue();

    inTenant($f['tenant'], fn () => $f['tenant']->forceFill(['upi_id' => 'institute@upi'])->save());

    // Queue with nothing in it — the empty state has its own string
    $emptyQueue = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/manage/payments");

    $emptyQueue->assertOk();
    $emptyQueue->assertSee(__('payments.queue.empty'));

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);

    // Student payment page
    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $page->assertOk();

    foreach ([
        'payments.page.heading',
        'payments.page.amount',
        'payments.page.upi_id',
        'payments.page.copy_upi',
        'payments.page.submit',
        'payments.page.upi_reference',
        'payments.status.pending',
    ] as $key) {
        $page->assertSee(__($key));
    }

    // Queue, now with a row in it
    $queue = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/manage/payments");

    $queue->assertOk();

    foreach ([
        'payments.queue.heading',
        'payments.queue.review',
        'payments.queue.columns.student',
        'payments.queue.columns.course',
        'payments.queue.columns.amount',
        'payments.queue.columns.submitted',
    ] as $key) {
        $queue->assertSee(__($key));
    }

    // Review page
    $review = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments/{$payment->id}");

    $review->assertOk();

    foreach ([
        'payments.review.heading',
        'payments.review.screenshot',
        'payments.review.upi_reference',
        'payments.review.approve',
        'payments.review.reject',
        'payments.review.confirm_reject',
    ] as $key) {
        $review->assertSee(__($key));
    }
});

test('a tenant with no UPI id shows the set-your-UPI-id prompt on the payment page', function () {
    $f = PaymentFixtures::setup();

    // Positive control: with nothing configured, the student sees the prompt
    $without = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $without->assertOk();
    $without->assertSee(__('payments.set_upi_id_prompt'));

    // …and once the owner has set one, the id itself is shown instead
    inTenant($f['tenant'], fn () => $f['tenant']->forceFill(['upi_id' => 'institute@upi'])->save());

    $with = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $with->assertOk();
    $with->assertSee('institute@upi');
    $with->assertDontSee(__('payments.set_upi_id_prompt'));
});

test('the owner saves a UPI id from institute settings', function () {
    $f = PaymentFixtures::setup();

    // Positive control: the settings form carries the field
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/settings")
        ->assertOk()
        ->assertSee('name="upi_id"', false);

    $this->actingAs($f['owner'], 'tenant')
        ->patch("http://{$f['domain']}/manage/settings", [
            'name' => $f['tenant']->name,
            'upi_id' => 'institute@upi',
        ])
        ->assertRedirect();

    expect((string) inTenant($f['tenant'], fn () => $f['tenant']->refresh()->upi_id))->toBe('institute@upi');
});

test('the owner dashboard shows a badge counting the payments waiting', function () {
    $f = PaymentFixtures::setup();

    // Positive control: nothing pending, no badge
    $empty = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard");

    $empty->assertOk();
    $empty->assertDontSee(__('payments.badge', ['count' => 1]));

    PaymentFixtures::payment($f['tenant'], $f['enrolment']);

    $badge = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard");

    $badge->assertOk();
    $badge->assertSee(__('payments.badge', ['count' => 1]));
});

test('the student dashboard links to the payment page', function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $page = $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard");

    $page->assertOk();
    $page->assertSee("/enrolments/{$f['enrolment']->id}/payment", false);
});

test("a student cannot open another student's payment page", function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $other = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);

    // Positive control: their own payment page renders
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment")
        ->assertOk();

    // Negative: someone else's enrolment in the same tenant is a 404, not a 403
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$other['enrolment']->id}/payment")
        ->assertNotFound();
});

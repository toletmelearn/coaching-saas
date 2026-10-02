<?php

use App\Enums\EnrolmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Enrolment;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PaymentFixtures;

test('the owner and a staff member both see the pending payment queue', function () {
    $f = PaymentFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments")
        ->assertOk();

    $this->actingAs($f['staff'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments")
        ->assertOk();
});

test('a student is forbidden from the payment queue and a guest is redirected to login', function () {
    $f = PaymentFixtures::setup();

    // Positive control: the owner gets in
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments")
        ->assertOk();

    // Negative: a student may not open the queue at all
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments")
        ->assertForbidden();

    // Negative: a guest is bounced to login rather than shown the queue
    $this->get("http://{$f['domain']}/manage/payments")
        ->assertRedirect('/login');
});

test('the queue lists pending payments oldest first, paginated at 25 and excluding reviewed ones', function () {
    $f = PaymentFixtures::setup();

    $oldestName = null;
    $newestName = null;

    for ($i = 0; $i < 26; $i++) {
        $extra = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);

        PaymentFixtures::payment($f['tenant'], $extra['enrolment'], [
            'submitted_at' => now()->subMinutes(100 - $i),
        ]);

        if ($i === 0) {
            $oldestName = $extra['student']->name;
        }

        if ($i === 25) {
            $newestName = $extra['student']->name;
        }
    }

    // A reviewed payment is not part of the pending queue
    $reviewed = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);
    PaymentFixtures::payment($f['tenant'], $reviewed['enrolment'], [
        'status' => PaymentStatus::Approved,
        'reviewed_at' => now(),
        'submitted_at' => now()->subMinute(),
    ]);

    $page1 = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/manage/payments");

    $page1->assertOk();
    $page1->assertSee(__('payments.queue.pending', ['count' => 26]));
    $page1->assertSee($oldestName);            // oldest first
    $page1->assertDontSee($newestName);        // …so the newest falls off a 25-row page
    $page1->assertDontSee($reviewed['student']->name);

    $page2 = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/manage/payments?page=2");

    $page2->assertOk();
    $page2->assertSee($newestName);            // the 26th payment is on page 2
});

test("the owner sees a payment's screenshot and the Approve and Reject controls", function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    Storage::disk('local')->put($payment->screenshot_path, file_get_contents(PaymentFixtures::image(640, 480)->getRealPath()));

    $page = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments/{$payment->id}");

    $page->assertOk();
    // The screenshot is served through a signed URL, never a public path
    $page->assertSee("/payments/{$payment->id}/screenshot", false);
    $page->assertSee('signature=', false);
    $page->assertSee('/approve', false);
    $page->assertSee('/reject', false);
    $page->assertSee('return confirm(', false);
});

test('a staff member can open a payment review but sees no Approve or Reject controls', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    Storage::disk('local')->put($payment->screenshot_path, file_get_contents(PaymentFixtures::image(640, 480)->getRealPath()));

    // Positive control: an owner is shown both controls
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments/{$payment->id}")
        ->assertOk()
        ->assertSee('/approve', false)
        ->assertSee('/reject', false);

    // Negative: staff can look, but the controls are not rendered for them
    $staffPage = $this->actingAs($f['staff'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments/{$payment->id}");

    $staffPage->assertOk();
    $staffPage->assertDontSee('/approve', false);
    $staffPage->assertDontSee('/reject', false);
});

test('a staff member is forbidden from approving or rejecting', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup();

    $extra = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);
    $forOwner = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    $forStaff = PaymentFixtures::payment($f['tenant'], $extra['enrolment']);

    // Positive control: the owner's approve lands
    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$forOwner->id}/approve")
        ->assertRedirect();

    expect(inTenant($f['tenant'], fn () => $forOwner->refresh()->status))->toBe(PaymentStatus::Approved);

    // Positive control: staff may still open the review page
    $this->actingAs($f['staff'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments/{$forStaff->id}")
        ->assertOk();

    // Negative: staff may not review — both endpoints 403, and the row is untouched
    $this->actingAs($f['staff'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$forStaff->id}/approve")
        ->assertForbidden();

    $this->actingAs($f['staff'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$forStaff->id}/reject", [
            'rejection_reason' => 'not mine to judge',
        ])
        ->assertForbidden();

    expect(inTenant($f['tenant'], fn () => $forStaff->refresh()->status))->toBe(PaymentStatus::Pending);
});

test('an owner can approve a payment without auto-enrolling anybody', function () {
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    $enrolmentsBefore = inTenant($f['tenant'], fn () => Enrolment::count());

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$payment->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('payment_notice', __('payments.review.approved'));

    expect(inTenant($f['tenant'], function () use ($payment, $f) {
        $payment->refresh();
        $f['enrolment']->refresh();

        return [
            $payment->status,
            (int) $payment->reviewed_by,
            $payment->reviewed_at !== null,
            (int) $f['enrolment']->approved_payment_id,
            $f['enrolment']->status,
            Enrolment::count(),
        ];
    }))->toBe([
        PaymentStatus::Approved,
        $f['owner']->id,
        true,
        $payment->id,
        EnrolmentStatus::Active,
        $enrolmentsBefore,
    ]);
});

test('an owner can reject a payment with a reason, and rejecting without one is allowed too', function () {
    $f = PaymentFixtures::setup();

    $extra = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);
    $withReason = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    $withoutReason = PaymentFixtures::payment($f['tenant'], $extra['enrolment']);

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$withReason->id}/reject", [
            'rejection_reason' => 'The screenshot is unreadable',
        ])
        ->assertRedirect()
        ->assertSessionHas('payment_notice', __('payments.review.rejected'));

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$withoutReason->id}/reject")
        ->assertRedirect();

    expect(inTenant($f['tenant'], function () use ($withReason, $withoutReason) {
        $withReason->refresh();
        $withoutReason->refresh();

        return [
            $withReason->status,
            $withReason->rejection_reason,
            (int) $withReason->reviewed_by,
            $withReason->reviewed_at !== null,
            $withoutReason->status,
            $withoutReason->rejection_reason,
        ];
    }))->toBe([
        PaymentStatus::Rejected,
        'The screenshot is unreadable',
        $f['owner']->id,
        true,
        PaymentStatus::Rejected,
        null,
    ]);
});

test('the rejection reason is optional but capped at 500 characters', function () {
    $f = PaymentFixtures::setup();

    $extra = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);
    $atLimit = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    $overLimit = PaymentFixtures::payment($f['tenant'], $extra['enrolment']);

    // Positive control: exactly 500 characters is accepted
    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$atLimit->id}/reject", [
            'rejection_reason' => str_repeat('x', 500),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(inTenant($f['tenant'], fn () => mb_strlen((string) $atLimit->refresh()->rejection_reason)))->toBe(500);

    // Negative: one character more is refused, and the payment is left pending
    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$overLimit->id}/reject", [
            'rejection_reason' => str_repeat('x', 501),
        ])
        ->assertSessionHasErrors('rejection_reason');

    expect(inTenant($f['tenant'], fn () => $overLimit->refresh()->status))->toBe(PaymentStatus::Pending);
});

test('approval is idempotent: a second approve is a no-op and enrolls nobody', function () {
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    $enrolmentsBefore = inTenant($f['tenant'], fn () => Enrolment::count());

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$payment->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('payment_notice', __('payments.review.approved'));

    $firstReviewedAt = inTenant($f['tenant'], fn () => $payment->refresh()->reviewed_at);

    // Double-click: the same POST again a few seconds later, auth re-resolved fresh
    $this->travel(10)->seconds();
    freshRequestCycle();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$payment->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('payment_notice', __('payments.review.already_approved'));

    expect(inTenant($f['tenant'], function () use ($payment, $f, $firstReviewedAt) {
        $payment->refresh();

        return [
            $payment->status,
            $payment->reviewed_at->equalTo($firstReviewedAt),   // reviewed_at did not move
            (int) $payment->reviewed_by,
            Enrolment::count(),                                  // nobody enrolled twice
            (int) $f['enrolment']->refresh()->approved_payment_id,
        ];
    }))->toBe([
        PaymentStatus::Approved,
        true,
        $f['owner']->id,
        $enrolmentsBefore,
        $payment->id,
    ]);
});

test('an approve after a reject is a 422, not a second review', function () {
    $f = PaymentFixtures::setup();

    $extra = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);
    $pending = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    $rejected = PaymentFixtures::payment($f['tenant'], $extra['enrolment']);

    // Positive control 1: approving a pending payment succeeds
    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$pending->id}/approve")
        ->assertRedirect();

    // Positive control 2: rejecting a pending payment succeeds
    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$rejected->id}/reject", [
            'rejection_reason' => 'blurry',
        ])
        ->assertRedirect();

    // Negative: the decision is final — approving a rejected payment is refused
    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/payments/{$rejected->id}/approve")
        ->assertUnprocessable();

    expect(inTenant($f['tenant'], function () use ($rejected) {
        $rejected->refresh();

        return [$rejected->status, $rejected->rejection_reason];
    }))->toBe([PaymentStatus::Rejected, 'blurry']);
});

test("another tenant's payment is not found", function () {
    $tenantA = PaymentFixtures::tenant('tenant-a.coaching.test');
    $tenantB = PaymentFixtures::tenant('tenant-b.coaching.test');

    $peopleA = PaymentFixtures::people($tenantA);
    $peopleB = PaymentFixtures::people($tenantB);

    $paymentA = PaymentFixtures::payment($tenantA, $peopleA['enrolment']);

    // Positive control: tenant A's owner opens their own payment
    $this->actingAs($peopleA['owner'], 'tenant')
        ->get("http://tenant-a.coaching.test/manage/payments/{$paymentA->id}")
        ->assertOk();

    // Negative: tenant B's owner asking for that same id gets a 404, not a 403
    $this->actingAs($peopleB['owner'], 'tenant')
        ->get("http://tenant-b.coaching.test/manage/payments/{$paymentA->id}")
        ->assertNotFound();
});

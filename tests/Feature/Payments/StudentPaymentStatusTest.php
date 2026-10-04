<?php

use App\Enums\PaymentStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use Tests\Support\PaymentFixtures;

// === Phase 15 Part B — dashboard greeting, payment status card, lesson gate ===

test('the student dashboard greets with Welcome back and the student name, like owner and staff', function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $response = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/dashboard");

    $response->assertOk();

    // The page header is the shared pattern: kicker = "Welcome back", title = the
    // name. The list below it keeps its own "My courses" heading.
    $response->assertSee(__('users.dashboard.welcome'))
        ->assertSee($f['student']->name)
        ->assertSee(__('nav.my_courses'));
});

test('the payment card shows the approved, under-review and needed states for the right courses', function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    // Enrolment 1: approved — the card must say "Payment received ... reference N".
    $approvedPayment = PaymentFixtures::payment($f['tenant'], $f['enrolment'], [
        'status' => PaymentStatus::Approved,
        'reviewed_at' => now(),
        'reviewed_by' => $f['owner']->id,
    ]);

    // Enrolment 2: submitted, awaiting review — "Payment under review ... submitted".
    [$pendingCourse, $pendingEnrolment] = inTenant($f['tenant'], function () use ($f) {
        $course = Course::factory()->published()->create(['fee_paise' => PaymentFixtures::FEE_PAISE]);
        $enrolment = Enrolment::factory()->for($course)->for($f['student'], 'user')->active()->create();

        return [$course, $enrolment];
    });
    $pendingPayment = PaymentFixtures::payment($f['tenant'], $pendingEnrolment);

    // Enrolment 3: nothing submitted — "Payment needed ... amount" + Pay now.
    [$neededCourse, $neededEnrolment] = inTenant($f['tenant'], function () use ($f) {
        $course = Course::factory()->published()->create(['fee_paise' => PaymentFixtures::FEE_PAISE]);
        $enrolment = Enrolment::factory()->for($course)->for($f['student'], 'user')->active()->create();

        return [$course, $enrolment];
    });

    $amount = __('payments.amount_format', ['amount' => number_format(PaymentFixtures::FEE_PAISE / 100, 2)]);
    $today = now()->format('d M Y');

    $response = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/dashboard");

    $response->assertOk()
        // Approved (green): received on the review date, with the payment reference.
        ->assertSee(__('payments.state.received', [
            'course' => $f['course']->title,
            'date' => $today,
            'reference' => $approvedPayment->id,
        ]))
        // Pending (amber): submitted date, no reference yet.
        ->assertSee(__('payments.state.under_review', [
            'course' => $pendingCourse->title,
            'date' => $today,
        ]))
        // Needed: the server-side amount plus a Pay now link into the existing page.
        ->assertSee(__('payments.state.needed', [
            'course' => $neededCourse->title,
            'amount' => $amount,
        ]))
        ->assertSee(__('payments.state.pay_now'))
        ->assertSee("/enrolments/{$neededEnrolment->id}/payment", false)
        // Cross-checks: every course appears in exactly one state, never another.
        ->assertDontSee(__('payments.state.under_review', [
            'course' => $f['course']->title,
            'date' => $today,
        ]))
        ->assertDontSee(__('payments.state.needed', [
            'course' => $f['course']->title,
            'amount' => $amount,
        ]))
        ->assertDontSee(__('payments.state.received', [
            'course' => $pendingCourse->title,
            'date' => $today,
            'reference' => $pendingPayment->id,
        ]))
        ->assertDontSee(__('payments.state.needed', [
            'course' => $pendingCourse->title,
            'amount' => $amount,
        ]))
        ->assertDontSee(__('payments.state.received', [
            'course' => $neededCourse->title,
            'date' => $today,
            'reference' => 1,
        ]))
        ->assertDontSee(__('payments.state.under_review', [
            'course' => $neededCourse->title,
            'date' => $today,
        ]));
});

test('a paid lesson shows Payment needed instead of the video player until the payment is approved', function () {
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $lesson = inTenant($f['tenant'], function () use ($f) {
        $chapter = Chapter::factory()->for($f['course'])->create();

        return Lesson::factory()->published()->for($chapter)->create([
            'course_id' => $f['course']->id,
            'is_free_preview' => false,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    });

    $url = "http://{$f['domain']}/courses/{$f['course']->slug}/lessons/{$lesson->id}";
    $amount = __('payments.amount_format', ['amount' => number_format(PaymentFixtures::FEE_PAISE / 100, 2)]);

    // No payment submitted: the state card replaces the player entirely.
    $response = $this->actingAs($f['student'], 'tenant')->get($url);

    $response->assertOk()
        ->assertSee(__('payments.state.needed', ['course' => $f['course']->title, 'amount' => $amount]))
        ->assertSee(__('payments.state.pay_now'))
        ->assertSee("/enrolments/{$f['enrolment']->id}/payment", false)
        ->assertDontSee('dQw4w9WgXcQ');

    // Positive control: once a payment reaches Approved, the same URL plays.
    PaymentFixtures::payment($f['tenant'], $f['enrolment'], [
        'status' => PaymentStatus::Approved,
        'reviewed_at' => now(),
        'reviewed_by' => $f['owner']->id,
    ]);

    $approved = $this->actingAs($f['student'], 'tenant')->get($url);

    $approved->assertOk()
        ->assertSee('dQw4w9WgXcQ')
        ->assertDontSee(__('payments.state.pay_now'));
});

test('a free course never shows the payment card or the lesson gate', function () {
    // No fee on the fixture course: nothing to pay, nothing to resolve.
    $f = PaymentFixtures::setup();

    $response = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/dashboard");

    $response->assertOk()
        ->assertDontSee(__('payments.state.heading'))
        ->assertDontSee(__('payments.state.pay_now'));

    $lesson = inTenant($f['tenant'], function () use ($f) {
        $chapter = Chapter::factory()->for($f['course'])->create();

        return Lesson::factory()->published()->for($chapter)->create([
            'course_id' => $f['course']->id,
            'is_free_preview' => false,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    });

    $lessonResponse = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/courses/{$f['course']->slug}/lessons/{$lesson->id}");

    $lessonResponse->assertOk()
        ->assertDontSee(__('payments.state.pay_now'))
        ->assertSee('dQw4w9WgXcQ');
});

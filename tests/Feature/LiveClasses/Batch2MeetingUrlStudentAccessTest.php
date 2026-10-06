<?php

use App\Models\Consent;
use App\Models\Course;
use App\Models\Enrolment;
use Tests\Support\LiveClassFixtures;
use Tests\Support\PaymentFixtures;

/**
 * Batch 2.2 — meeting_url student access.
 *
 * Every test must fail on the old code because the "Join class" button (and the
 * server-side guard that suppresses the URL for blocked students) does not exist
 * yet — any assertion that an allowed student *sees* the button fails immediately.
 *
 * Label used for the external-link button:
 *   live_classes.meeting_url_button  ("Join class")
 * This key does not exist yet, so assertSee(__('live_classes.meeting_url_button'))
 * would resolve to the key string — choose a concrete sentinel instead and also
 * assert it via the key once the lang file is updated.
 */

const MEETING_URL_SENTINEL = 'https://zoom.us/j/batch2test999';
const JOIN_CLASS_TEXT = 'Join class';

// ---- owner always sees it ------------------------------------------------------------------

test('2.2 owner always sees the meeting_url button regardless of class status', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // Set the meeting_url on the class (requires the column to exist).
    inTenant($f['tenant'], fn () => $class->forceFill(['meeting_url' => MEETING_URL_SENTINEL])->save());

    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(JOIN_CLASS_TEXT)
        ->assertSee(MEETING_URL_SENTINEL, false);
});

// ---- enrolled free-course student sees button -----------------------------------------------

test('2.2 enrolled student in a free course sees the Join class button when the class is live', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    inTenant($f['tenant'], fn () => $class->forceFill(['meeting_url' => MEETING_URL_SENTINEL])->save());

    // Positive control: enrolled student sees the button.
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(JOIN_CLASS_TEXT)
        ->assertSee(MEETING_URL_SENTINEL, false);
});

test('2.2 enrolled student in a free course sees the Join class button when the class is scheduled (within window)', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'scheduled',
    ]);
    inTenant($f['tenant'], fn () => $class->forceFill(['meeting_url' => MEETING_URL_SENTINEL])->save());

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(JOIN_CLASS_TEXT)
        ->assertSee(MEETING_URL_SENTINEL, false);
});

// ---- unenrolled student cannot see URL -----------------------------------------------------

test('2.2 unenrolled student cannot see the meeting_url in the page source', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    inTenant($f['tenant'], fn () => $class->forceFill(['meeting_url' => MEETING_URL_SENTINEL])->save());

    // Positive control first (enrolled student must see the button — if this fails
    // on old code the test fails here, before the negative assertion even runs).
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(JOIN_CLASS_TEXT);

    // Negative: the outsider has no enrolment so the page should 403.
    // The meeting URL must NOT appear in any response the outsider receives.
    $response = $this->actingAs($f['outsider'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}");

    $response->assertForbidden();
    expect($response->getContent())->not->toContain(MEETING_URL_SENTINEL);
});

// ---- unpaid student on a paid course cannot see URL ----------------------------------------

test('2.2 unpaid student on a paid course cannot see the meeting_url in the page source', function () {
    $f = LiveClassFixtures::setup();

    // Make the course paid.
    inTenant($f['tenant'], fn () => Course::query()->whereKey($f['course']->id)->update(['fee_paise' => 49900]));

    // Student is enrolled but has no approved payment.
    PaymentFixtures::payment($f['tenant'], inTenant($f['tenant'], fn () => Enrolment::query()->where('user_id', $f['student']->id)->firstOrFail()), ['status' => \App\Enums\PaymentStatus::Pending]);

    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    inTenant($f['tenant'], fn () => $class->forceFill(['meeting_url' => MEETING_URL_SENTINEL])->save());

    // Positive control: the owner always sees the URL.
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(JOIN_CLASS_TEXT);

    // Negative: the unpaid student is refused the page.
    $response = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}");

    $response->assertForbidden();
    expect($response->getContent())->not->toContain(MEETING_URL_SENTINEL);
});

// ---- consent-withdrawn student cannot see URL -----------------------------------------------

test('2.2 consent-withdrawn student cannot see the meeting_url in the page source', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    inTenant($f['tenant'], fn () => $class->forceFill(['meeting_url' => MEETING_URL_SENTINEL])->save());

    // Withdraw course_delivery consent for the student.
    inTenant($f['tenant'], function () use ($f) {
        $consent = new Consent;
        $consent->forceFill([
            'tenant_id' => $f['tenant']->id,
            'user_id' => $f['student']->id,
            'purpose' => 'course_delivery',
            'method' => 'owner_attested',
            'recorded_by' => $f['owner']->id,
            'notice_version' => Consent::NOTICE_VERSION,
            'granted_at' => now()->subDay(),
            'withdrawn_at' => now(),
        ])->save();
    });

    // Positive control: owner sees the URL.
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(JOIN_CLASS_TEXT);

    // Negative: consent withdrawn → page 403.
    $response = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}");

    $response->assertForbidden();
    expect($response->getContent())->not->toContain(MEETING_URL_SENTINEL);
});

// ---- staff always sees it ------------------------------------------------------------------

test('2.2 staff always sees the meeting_url button', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    inTenant($f['tenant'], fn () => $class->forceFill(['meeting_url' => MEETING_URL_SENTINEL])->save());

    $this->actingAs($f['staff'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(JOIN_CLASS_TEXT)
        ->assertSee(MEETING_URL_SENTINEL, false);
});

// ---- no meeting_url set: no button shown ---------------------------------------------------

test('2.2 no button shown when meeting_url is null (only the meeting_url button, not the JaaS join)', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    // meeting_url is null. Set it and then clear it so the forceFill test path runs.
    inTenant($f['tenant'], fn () => $class->forceFill(['meeting_url' => null])->save());

    // The owner should NOT see the meeting-url "Join class" button.
    // Fails on old code because forceFill tries to save meeting_url to a missing column.
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertDontSee(JOIN_CLASS_TEXT);
});

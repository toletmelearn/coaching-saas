<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConsentFixtures;

// === Withdrawing consent ===

test('the owner can withdraw a recorded consent and the row survives as an audit record', function () {
    $f = ConsentFixtures::setup();
    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::withdrawUrl($f['domain'], $f['student'], $consent->id), [
            'reason' => 'Guardian asked to pause the course',
        ])
        ->assertRedirect();

    $row = DB::table('consents')->where('id', $consent->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->withdrawn_at)->not->toBeNull()
        ->and($row->withdrawn_reason)->toBe('Guardian asked to pause the course')
        // Withdrawal never deletes the grant it cancels: both sides of the
        // history stay readable.
        ->and($row->granted_at)->not->toBeNull()
        ->and($row->purpose)->toBe('course_delivery');
});

test('staff can withdraw a recorded consent exactly like the owner', function () {
    $f = ConsentFixtures::setup();
    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'communication');

    $this->actingAs($f['staff'], 'tenant')
        ->post(ConsentFixtures::withdrawUrl($f['domain'], $f['student'], $consent->id), [
            'reason' => 'No more WhatsApp updates please',
        ])
        ->assertRedirect();

    $row = DB::table('consents')->where('id', $consent->id)->first();

    expect($row->withdrawn_at)->not->toBeNull()
        ->and($row->withdrawn_reason)->toBe('No more WhatsApp updates please');
});

test('a withdrawal without a reason is rejected and changes nothing', function () {
    $f = ConsentFixtures::setup();
    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::withdrawUrl($f['domain'], $f['student'], $consent->id), [])
        ->assertSessionHasErrors('reason');

    $row = DB::table('consents')->where('id', $consent->id)->first();

    expect($row->withdrawn_at)->toBeNull()
        ->and($row->withdrawn_reason)->toBeNull();
});

// === Side effects of withdrawal ===

test('a student whose course_delivery consent was withdrawn cannot reach their course or its lessons', function () {
    $f = ConsentFixtures::setup();
    $lesson = ConsentFixtures::lesson($f['tenant'], $f['course']);
    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');

    $courseUrl = "http://{$f['domain']}/courses/{$f['course']->slug}";
    $lessonUrl = "{$courseUrl}/lessons/{$lesson->id}";

    // Positive controls: while the consent stands, the enrolled student reaches
    // both pages — so the 403s below are caused by the withdrawal, not by a
    // broken fixture or a missing route.
    $this->actingAs($f['student'], 'tenant')->get($courseUrl)->assertOk();
    $this->actingAs($f['student'], 'tenant')->get($lessonUrl)->assertOk();

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::withdrawUrl($f['domain'], $f['student'], $consent->id), [
            'reason' => 'Consent withdrawn by guardian',
        ])
        ->assertRedirect();

    $this->actingAs($f['student'], 'tenant')->get($courseUrl)->assertForbidden();
    $this->actingAs($f['student'], 'tenant')->get($lessonUrl)->assertForbidden();

    // The block is consent-based, not a lock-out: the institute's staff still
    // see their own course.
    $this->actingAs($f['owner'], 'tenant')->get($courseUrl)->assertOk();
});

test('a student whose progress_tracking consent was withdrawn has their progress data erased', function () {
    $f = ConsentFixtures::setup();
    $lessonOne = ConsentFixtures::lesson($f['tenant'], $f['course']);
    $lessonTwo = ConsentFixtures::lesson($f['tenant'], $f['course']);

    ConsentFixtures::progress($f['tenant'], $f['course'], $lessonOne, $f['student']);
    ConsentFixtures::progress($f['tenant'], $f['course'], $lessonTwo, $f['student']);

    // Another student's progress must not be caught in the erasure.
    $other = inTenant($f['tenant'], fn () => User::factory()->student()->create());
    ConsentFixtures::progress($f['tenant'], $f['course'], $lessonOne, $other);

    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'progress_tracking');

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::withdrawUrl($f['domain'], $f['student'], $consent->id), [
            'reason' => 'Guardian opted out of tracking',
        ])
        ->assertRedirect();

    expect(DB::table('lesson_progress')->where('user_id', $f['student']->id)->count())->toBe(0)
        ->and(DB::table('lesson_progress')->where('user_id', $other->id)->count())->toBe(1);

    // The withdrawal itself is still an auditable record.
    $row = DB::table('consents')->where('id', $consent->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->withdrawn_at)->not->toBeNull()
        ->and($row->withdrawn_reason)->toBe('Guardian opted out of tracking');
});

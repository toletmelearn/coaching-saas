<?php

use App\Models\Enrolment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConsentFixtures;
use Tests\Support\PaymentFixtures;

// === Erasure ===

test('the owner can erase a student: the row is anonymised, progress and devices are deleted, consent records survive', function () {
    $f = ConsentFixtures::erasureSetup();
    ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ])
        ->assertRedirect();

    $student = inTenant($f['tenant'], fn () => User::find($f['student']->id));

    expect($student)->not->toBeNull()
        ->and($student->name)->toBe(ConsentFixtures::ERASED_NAME)
        // Decision 1 (Phase 15): a sentinel on the reserved .invalid TLD, not
        // null — users_email_or_phone_check keeps requiring one contact column,
        // and .invalid can never resolve or receive mail. The row id keeps it
        // unique inside the tenant's (tenant_id, email) index.
        ->and($student->email)->toBe('erased-'.$f['student']->id.'@removed.invalid')
        ->and($student->phone)->toBeNull()
        ->and($student->guardian_name)->toBeNull()
        ->and($student->guardian_relationship)->toBeNull()
        ->and($student->guardian_phone)->toBeNull()
        ->and($student->guardian_email)->toBeNull();

    expect(DB::table('lesson_progress')->where('user_id', $f['student']->id)->count())->toBe(0)
        ->and(DB::table('user_devices')->where('user_id', $f['student']->id)->count())->toBe(0);

    // The consent history is the audit trail of what was collected and when —
    // it documents the erasure rather than carrying personal data, so it stays.
    expect(DB::table('consents')->where('user_id', $f['student']->id)->count())->toBe(1);
});

test('erasure hard-deletes enrolments and live-class attendance and snapshots them to the erasure log', function () {
    $f = ConsentFixtures::erasureSetup();

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ])
        ->assertRedirect();

    // Decision 4 (Phase 15): the rows are really gone — no soft delete — and
    // their key fields survive as JSON in consent_audit_logs.metadata.
    expect(DB::table('enrolments')->where('id', $f['enrolment']->id)->count())->toBe(0)
        ->and(DB::table('live_class_attendance')->where('id', $f['attendance']->id)->count())->toBe(0);

    $row = DB::table('consent_audit_logs')
        ->where('action', 'student_erased')
        ->where('user_id', $f['student']->id)
        ->first();

    $metadata = json_decode((string) $row->metadata, true);

    expect($metadata)->toHaveKeys(['enrolments', 'live_class_attendance', 'payments'])
        ->and(collect($metadata['enrolments'])->pluck('id')->all())->toContain($f['enrolment']->id)
        ->and(collect($metadata['live_class_attendance'])->pluck('id')->all())->toContain($f['attendance']->id);
});

test('erasure removes the payment row with its enrolment but keeps the financial record as a snapshot', function () {
    $f = ConsentFixtures::erasureSetup();

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ])
        ->assertRedirect();

    // payments.enrolment_id is ON DELETE CASCADE, so the payment row leaves
    // with the hard-deleted enrolment (Decision 4) — the financial facts it
    // carried are what the snapshot below keeps.
    expect(inTenant($f['tenant'], fn () => Payment::find($f['payment']->id)))->toBeNull();

    $row = DB::table('consent_audit_logs')
        ->where('action', 'student_erased')
        ->where('user_id', $f['student']->id)
        ->first();

    $metadata = json_decode((string) $row->metadata, true);
    $snapshot = collect($metadata['payments'])->firstWhere('id', $f['payment']->id);

    expect($snapshot)->not->toBeNull()
        ->and((int) $snapshot['amount_paise'])->toBe(PaymentFixtures::FEE_PAISE)
        ->and((string) $snapshot['status'])->toBe('pending')
        // The student behind the snapshot is the anonymised row, not a name.
        ->and(inTenant($f['tenant'], fn () => User::find($f['student']->id))->name)
        ->toBe(ConsentFixtures::ERASED_NAME);
});

test('erasure writes a consent_audit_logs entry naming the student and the actor', function () {
    $f = ConsentFixtures::erasureSetup();

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ])
        ->assertRedirect();

    $row = DB::table('consent_audit_logs')
        ->where('action', 'student_erased')
        ->where('tenant_id', $f['tenant']->id)
        ->where('user_id', $f['student']->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->actor_id)->toBe($f['owner']->id);
});

test('erasure demands the student name as confirmation and changes nothing on a mismatch', function () {
    $f = ConsentFixtures::erasureSetup();
    $url = ConsentFixtures::dataUrl($f['domain'], $f['student']);

    $this->actingAs($f['owner'], 'tenant')
        ->from($url)
        ->delete($url, ['confirmation' => 'Completely Different Name'])
        ->assertSessionHasErrors('confirmation', __('consents.errors.confirmation_mismatch'));

    $student = inTenant($f['tenant'], fn () => User::find($f['student']->id));

    expect($student->name)->not->toBe(ConsentFixtures::ERASED_NAME)
        ->and($student->email)->not->toBeNull()
        ->and(DB::table('lesson_progress')->where('user_id', $f['student']->id)->count())->toBe(1)
        ->and(DB::table('user_devices')->where('user_id', $f['student']->id)->count())->toBe(1)
        ->and(inTenant($f['tenant'], fn () => Enrolment::find($f['enrolment']->id)))->not->toBeNull()
        ->and(DB::table('consent_audit_logs')->where('user_id', $f['student']->id)->count())->toBe(0);
});

test('erasure leaves other students in the same tenant untouched', function () {
    $f = ConsentFixtures::erasureSetup();

    [$other, $otherEnrolment] = inTenant($f['tenant'], function () use ($f) {
        $other = User::factory()->student()->create([
            'name' => 'Other Kid',
            'phone' => '9876599999',
        ]);
        $enrolment = Enrolment::factory()->for($f['course'])->for($other, 'user')->active()->create();

        return [$other, $enrolment];
    });
    ConsentFixtures::progress($f['tenant'], $f['course'], $f['lesson'], $other);

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ])
        ->assertRedirect();

    $refreshed = inTenant($f['tenant'], fn () => User::find($other->id));

    expect($refreshed->name)->toBe('Other Kid')
        ->and($refreshed->phone)->toBe('9876599999')
        ->and(DB::table('lesson_progress')->where('user_id', $other->id)->count())->toBe(1)
        ->and(inTenant($f['tenant'], fn () => Enrolment::find($otherEnrolment->id)))->not->toBeNull();
});

test('staff can erase a student like the owner', function () {
    $f = ConsentFixtures::erasureSetup();

    $this->actingAs($f['staff'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ])
        ->assertRedirect();

    $student = inTenant($f['tenant'], fn () => User::find($f['student']->id));

    expect($student->name)->toBe(ConsentFixtures::ERASED_NAME);
});

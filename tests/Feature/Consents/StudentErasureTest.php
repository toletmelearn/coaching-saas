<?php

use App\Models\Enrolment;
use App\Models\LiveClassAttendance;
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
        ->and($student->email)->toBeNull()
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

test('erasure soft-deletes enrolments and live-class attendance instead of dropping them', function () {
    $f = ConsentFixtures::erasureSetup();

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ])
        ->assertRedirect();

    expect(inTenant($f['tenant'], fn () => Enrolment::find($f['enrolment']->id)))->toBeNull()
        ->and(inTenant($f['tenant'], fn () => Enrolment::withTrashed()->find($f['enrolment']->id)))->not->toBeNull()
        ->and(inTenant($f['tenant'], fn () => LiveClassAttendance::find($f['attendance']->id)))->toBeNull()
        ->and(inTenant($f['tenant'], fn () => LiveClassAttendance::withTrashed()->find($f['attendance']->id)))->not->toBeNull();

    expect(DB::table('enrolments')->where('id', $f['enrolment']->id)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('live_class_attendance')->where('id', $f['attendance']->id)->value('deleted_at'))->not->toBeNull();
});

test('erasure keeps payments as financial records but the link resolves only to the anonymised row', function () {
    $f = ConsentFixtures::erasureSetup();

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ])
        ->assertRedirect();

    $payment = inTenant($f['tenant'], fn () => Payment::find($f['payment']->id));

    expect($payment)->not->toBeNull()
        ->and((int) $payment->amount_paise)->toBe(PaymentFixtures::FEE_PAISE);

    // Following the payment → enrolment → student chain lands on an anonymous row.
    $enrolment = inTenant($f['tenant'], fn () => Enrolment::withTrashed()->find($f['enrolment']->id));
    $linked = inTenant($f['tenant'], fn () => User::find($enrolment->user_id));

    expect($linked)->not->toBeNull()
        ->and($linked->name)->toBe(ConsentFixtures::ERASED_NAME);
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

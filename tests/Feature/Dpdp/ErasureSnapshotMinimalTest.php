<?php

use App\Models\ConsentAuditLog;
use App\Models\Enrolment;
use App\Models\Payment;
use Tests\Support\ConsentFixtures;
use Tests\TestCase;

/**
 * The erasure snapshot keeps only what the financial and participation record needs: ids, amounts,
 * status, timestamps, course id, and for attendance the class id and duration. Free-text payment
 * notes, UPI references and screenshot paths are dropped.
 */
function p16SnapshotFixture(TestCase $t): array
{
    $f = ConsentFixtures::erasureSetup();

    inTenant($f['tenant'], function () use ($f) {
        Enrolment::find($f['enrolment']->id)->forceFill(['payment_note' => 'Paid by Ravi Kumar, cheque 4411'])->save();
        Payment::find($f['payment']->id)->forceFill(['upi_reference' => 'UPI-REF-7788990011'])->save();
    });

    $t->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), ['confirmation' => $f['student']->name])
        ->assertRedirect();

    $row = inTenant($f['tenant'], fn () => ConsentAuditLog::where('action', ConsentAuditLog::ACTION_STUDENT_ERASED)->first());

    return [$f, $row->metadata];
}

test('the enrolment snapshot drops payment_note and keeps ids, status and timestamps (positive control: the snapshot holds the enrolment)', function () {
    [$f, $metadata] = p16SnapshotFixture($this);

    expect(collect($metadata['enrolments'])->pluck('id')->all())->toContain($f['enrolment']->id);

    $enrolment = collect($metadata['enrolments'])->firstWhere('id', $f['enrolment']->id);
    expect($enrolment)->toHaveKeys(['id', 'course_id', 'status'])
        ->and($enrolment)->not->toHaveKey('payment_note');
});

test('the payment snapshot drops the UPI reference and screenshot path and keeps amount, status and timestamps (positive control: the snapshot holds the payment)', function () {
    [$f, $metadata] = p16SnapshotFixture($this);

    $payment = collect($metadata['payments'])->firstWhere('id', $f['payment']->id);
    expect($payment)->not->toBeNull()
        ->and($payment)->toHaveKeys(['id', 'amount_paise', 'status', 'submitted_at'])
        ->and($payment)->not->toHaveKey('upi_reference')
        ->and($payment)->not->toHaveKey('screenshot_path');
});

test('the attendance snapshot keeps only the class id and duration (positive control: the class id is present)', function () {
    [$f, $metadata] = p16SnapshotFixture($this);

    $attendance = collect($metadata['live_class_attendance'])->firstWhere('id', $f['attendance']->id);
    expect($attendance)->not->toBeNull()
        ->and($attendance)->toHaveKeys(['live_class_id', 'duration_seconds'])
        ->and($attendance)->not->toHaveKeys(['user_id', 'joined_at', 'last_seen_at', 'left_at']);
});

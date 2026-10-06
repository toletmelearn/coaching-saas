<?php

use App\Models\AdminAuditLog;
use App\Models\LiveClassAttendance;
use Tests\Support\LiveClassFixtures;

/**
 * Batch 2.3 — manual attendance marking.
 *
 * Routes under test (do not exist yet):
 *   POST   /manage/courses/{course}/live-classes/{liveClass}/attendance/{user}/mark
 *   DELETE /manage/courses/{course}/live-classes/{liveClass}/attendance/{user}/mark
 *
 * Every test fails on old code because those routes return 404.
 */

function b2ManualAttendanceMarkUrl(array $f, object $class, object $user): string
{
    return "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/attendance/{$user->id}/mark";
}

// ---- mark present --------------------------------------------------------------------------

test('2.3 owner can mark a student present and an attendance row is written', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // Positive control first so the test fails here on old code (404).
    $this->actingAs($f['owner'], 'tenant')
        ->post(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    // A live_class_attendance row must exist for this student.
    $rows = inTenant($f['tenant'], fn () => LiveClassAttendance::where('live_class_id', $class->id)
        ->where('user_id', $f['student']->id)
        ->get());

    expect($rows)->toHaveCount(1);
    expect($rows[0]->joined_at)->not->toBeNull();
});

test('2.3 staff can also mark a student present', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['staff'], 'tenant')
        ->post(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    expect(inTenant($f['tenant'], fn () => LiveClassAttendance::where('live_class_id', $class->id)
        ->where('user_id', $f['student']->id)
        ->exists()))->toBeTrue();
});

// ---- unmark ---------------------------------------------------------------------------------

test('2.3 owner can unmark a student and the attendance row is removed', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // First mark present so there is a row to remove.
    $this->actingAs($f['owner'], 'tenant')
        ->post(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    expect(inTenant($f['tenant'], fn () => LiveClassAttendance::where('live_class_id', $class->id)
        ->where('user_id', $f['student']->id)
        ->exists()))->toBeTrue();

    // Now unmark (DELETE).
    $this->actingAs($f['owner'], 'tenant')
        ->delete(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    // Row must be gone (or have a left_at set — either satisfies the spec).
    $remaining = inTenant($f['tenant'], fn () => LiveClassAttendance::where('live_class_id', $class->id)
        ->where('user_id', $f['student']->id)
        ->whereNull('left_at')
        ->count());

    expect($remaining)->toBe(0);
});

// ---- student cannot mark --------------------------------------------------------------------

test('2.3 student cannot mark themselves or others present (403)', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // Positive control: the owner's request succeeds.
    $this->actingAs($f['owner'], 'tenant')
        ->post(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    // Negative: the student tries to mark *another* user — must be 403.
    $this->actingAs($f['student'], 'tenant')
        ->post(b2ManualAttendanceMarkUrl($f, $class, $f['outsider']))
        ->assertForbidden();
});

// ---- audit log written ----------------------------------------------------------------------

test('2.3 marking present writes an admin_audit_logs row with action attendance_manual', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['owner'], 'tenant')
        ->post(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'attendance_manual',
    ]);
});

test('2.3 unmarking also writes an admin_audit_logs row with action attendance_manual', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['owner'], 'tenant')
        ->post(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    $countBefore = AdminAuditLog::where('action', 'attendance_manual')->count();

    $this->actingAs($f['owner'], 'tenant')
        ->delete(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    expect(AdminAuditLog::where('action', 'attendance_manual')->count())->toBeGreaterThan($countBefore);
});

// ---- existing automatic heartbeat attendance is not broken ----------------------------------

test('2.3 existing heartbeat attendance is not broken by the manual mark endpoint', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // Student joins via normal heartbeat path.
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$class->id}/heartbeat")
        ->assertNoContent();

    // Owner also manually marks the student.
    $this->actingAs($f['owner'], 'tenant')
        ->post(b2ManualAttendanceMarkUrl($f, $class, $f['student']))
        ->assertSuccessful();

    // Both rows (automatic + manual) may coexist OR the manual call may be a no-op
    // when a row already exists — either is acceptable; the important thing is no crash.
    $count = inTenant($f['tenant'], fn () => LiveClassAttendance::where('live_class_id', $class->id)
        ->where('user_id', $f['student']->id)
        ->count());

    expect($count)->toBeGreaterThanOrEqual(1);
});

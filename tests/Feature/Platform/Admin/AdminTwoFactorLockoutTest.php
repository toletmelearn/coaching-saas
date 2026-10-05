<?php

use App\Models\AdminAuditLog;
use App\Models\PlatformAdmin;
use App\Support\Auth\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Hard lock: 20 failed 2FA attempts within an hour lock verification for an hour. The lock
 * refuses correct TOTP codes and recovery codes alike, every failed attempt is written to the
 * admin audit log, and the lock lifts when the hour has passed.
 */

const P161_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
const P161_RECOVERY = 'abcde-fghij';
const P161_FAILED_ACTION = 'two_factor_failed';

function p161Admin(): PlatformAdmin
{
    $admin = PlatformAdmin::factory()->create(['password' => 'correct-horse-battery']);
    $admin->forceFill([
        'totp_secret' => P161_SECRET,
        'totp_enabled_at' => now(),
        'recovery_codes' => [Hash::make(P161_RECOVERY)],
    ])->save();

    return $admin;
}

function p161Login(\Tests\TestCase $t, PlatformAdmin $admin): void
{
    $t->post('http://coaching.test/admin/login', ['email' => $admin->email, 'password' => 'correct-horse-battery'])
        ->assertRedirect('http://coaching.test/admin/two-factor');
}

test('20 failed codes lock verification: a correct code and a recovery code are both refused, and every failure is audited (positive control: the first wrong code is an ordinary error)', function () {
    $admin = p161Admin();
    p161Login($this, $admin);

    // Positive control: a single wrong code is an ordinary validation error, not a lock.
    $this->post('http://coaching.test/admin/two-factor', ['code' => '000000'])
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('admin_2fa_locked');

    // Nineteen more failures, twelve seconds apart: stays inside the five-per-minute limit.
    for ($i = 0; $i < 19; $i++) {
        $this->travel(12)->seconds();
        $this->post('http://coaching.test/admin/two-factor', ['code' => '000000']);
    }

    expect(AdminAuditLog::where('admin_id', $admin->id)->where('action', P161_FAILED_ACTION)->count())->toBe(20);

    // The lock now refuses a correct TOTP code.
    $this->travel(12)->seconds();
    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P161_SECRET, time())])
        ->assertSessionHasErrors('code');
    expect(Auth::guard('platform_admin')->guest())->toBeTrue();

    // ...and an unused recovery code.
    $this->post('http://coaching.test/admin/two-factor', ['code' => P161_RECOVERY])
        ->assertSessionHasErrors('code');
    expect(Auth::guard('platform_admin')->guest())->toBeTrue();
    expect($admin->fresh()->recovery_codes)->toHaveCount(1);
});

test('the lock lifts after an hour: a correct code works again (the success after the hour is the positive control)', function () {
    $admin = p161Admin();
    p161Login($this, $admin);

    for ($i = 0; $i < 20; $i++) {
        $this->travel(12)->seconds();
        $this->post('http://coaching.test/admin/two-factor', ['code' => '000000']);
    }

    // Positive control inside the test: the lock is in force before the hour has passed. Without it
    // this test would pass today, because no lock exists, and would prove nothing about lifting.
    $this->travel(12)->seconds();
    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P161_SECRET, time())])
        ->assertSessionHasErrors('code');

    $this->travel(61)->minutes();
    p161Login($this, $admin);

    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P161_SECRET, time())])
        ->assertRedirect('http://coaching.test/admin/dashboard');
});

<?php

use App\Models\AdminAuditLog;
use App\Models\PlatformAdmin;
use App\Support\Auth\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Hard lock: the 20th failed 2FA attempt within an hour locks verification for an hour. The lock is
 * keyed by admin, not session, so a fresh password login does not clear it. While locked, TOTP codes
 * and recovery codes are refused alike. Every failed attempt is written to the admin audit log.
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

function p161Login(TestCase $t, PlatformAdmin $admin): void
{
    $t->post('http://coaching.test/admin/login', ['email' => $admin->email, 'password' => 'correct-horse-battery'])
        ->assertRedirect('http://coaching.test/admin/two-factor');
}

function p161Fail(TestCase $t, int $times): void
{
    // Twelve seconds apart stays inside the five-per-minute attempt limit.
    for ($i = 0; $i < $times; $i++) {
        $t->travel(12)->seconds();
        $t->post('http://coaching.test/admin/two-factor', ['code' => '000000']);
    }
}

test('19 failed codes leave the correct code working, and every failure is audited (positive control: the correct code logs in)', function () {
    $admin = p161Admin();
    p161Login($this, $admin);

    p161Fail($this, 19);

    expect(AdminAuditLog::where('admin_id', $admin->id)->where('action', P161_FAILED_ACTION)->count())->toBe(19);

    $this->travel(12)->seconds();
    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P161_SECRET, time())])
        ->assertRedirect('http://coaching.test/admin/dashboard');
});

test('the 20th failed code locks verification: a correct TOTP code is refused (positive control: the 19th-attempt login above proves the code is valid)', function () {
    $admin = p161Admin();
    p161Login($this, $admin);

    p161Fail($this, 20);

    expect(AdminAuditLog::where('admin_id', $admin->id)->where('action', P161_FAILED_ACTION)->count())->toBe(20);

    $this->travel(12)->seconds();
    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P161_SECRET, time())])
        ->assertSessionHasErrors('code');
    expect(Auth::guard('platform_admin')->guest())->toBeTrue();
});

test('the lock holds across a fresh password login (keyed by admin, not session)', function () {
    $admin = p161Admin();
    p161Login($this, $admin);
    p161Fail($this, 20);

    // A brand-new session: log in with the password again, then present a correct code.
    $this->flushSession();
    freshRequestCycle();
    p161Login($this, $admin);

    $this->travel(12)->seconds();
    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P161_SECRET, time())])
        ->assertSessionHasErrors('code');
    expect(Auth::guard('platform_admin')->guest())->toBeTrue();
});

test('while locked, an unused recovery code is refused and not consumed (positive control: the code is accepted before the lock)', function () {
    $admin = p161Admin();
    p161Login($this, $admin);

    // Positive control: a second admin with no failures accepts the same recovery code.
    $control = p161Admin();
    p161Login($this, $control);
    $this->post('http://coaching.test/admin/two-factor', ['code' => P161_RECOVERY])
        ->assertRedirect('http://coaching.test/admin/dashboard');

    freshRequestCycle();
    $this->flushSession();
    p161Login($this, $admin);
    p161Fail($this, 20);

    $this->travel(12)->seconds();
    $this->post('http://coaching.test/admin/two-factor', ['code' => P161_RECOVERY])
        ->assertSessionHasErrors('code');
    expect($admin->fresh()->recovery_codes)->toHaveCount(1);
});

test('the lock lifts after an hour: a correct code works again (the refusal during the hour is the positive control)', function () {
    $admin = p161Admin();
    p161Login($this, $admin);
    p161Fail($this, 20);

    $this->travel(12)->seconds();
    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P161_SECRET, time())])
        ->assertSessionHasErrors('code');

    $this->travel(61)->minutes();
    p161Login($this, $admin);

    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P161_SECRET, time())])
        ->assertRedirect('http://coaching.test/admin/dashboard');
});

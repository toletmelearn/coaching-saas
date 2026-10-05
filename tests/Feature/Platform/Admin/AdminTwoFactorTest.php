<?php

use App\Models\PlatformAdmin;
use App\Support\Auth\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 §3 — TOTP 2FA for platform admins. A correct password alone never
 * authenticates an admin whose 2FA is enrolled: they are sent to a challenge page, and only
 * a valid, unreplayed 6-digit code completes the login.
 */
const P16_TOTP_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 of "12345678901234567890" (RFC 6238)

function p16EnrolledAdmin(string $password = 'correct-horse-battery'): PlatformAdmin
{
    expect(Schema::hasColumn('platform_admins', 'totp_secret'))->toBeTrue();
    $admin = PlatformAdmin::factory()->create(['password' => $password]);
    $admin->forceFill(['totp_secret' => P16_TOTP_SECRET, 'totp_enabled_at' => now()])->save();

    return $admin;
}

test('an enrolled admin with the correct password is sent to the 2FA challenge and is not logged in', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    $admin = p16EnrolledAdmin();

    $this->post('http://coaching.test/admin/login', [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect('http://coaching.test/admin/two-factor');

    expect(Auth::guard('platform_admin')->guest())->toBeTrue();
});

test('the correct TOTP code completes the login (positive control)', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    $admin = p16EnrolledAdmin();

    $this->post('http://coaching.test/admin/login', [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ]);

    $code = Totp::code(P16_TOTP_SECRET, time());

    $this->post('http://coaching.test/admin/two-factor', ['code' => $code])
        ->assertRedirect('http://coaching.test/admin/dashboard');
});

test('a wrong TOTP code is refused and the admin stays logged out (positive control: the right code works in the same test)', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    $admin = p16EnrolledAdmin();

    $this->post('http://coaching.test/admin/login', [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ]);

    $this->post('http://coaching.test/admin/two-factor', ['code' => '000000'])
        ->assertSessionHasErrors('code');
    expect(Auth::guard('platform_admin')->guest())->toBeTrue();

    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P16_TOTP_SECRET, time())])
        ->assertRedirect('http://coaching.test/admin/dashboard');
});

test('a TOTP code cannot be replayed within its own time window (positive control: first use succeeds)', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    $admin = p16EnrolledAdmin();
    $code = Totp::code(P16_TOTP_SECRET, time());

    $this->post('http://coaching.test/admin/login', ['email' => $admin->email, 'password' => 'correct-horse-battery']);
    $this->post('http://coaching.test/admin/two-factor', ['code' => $code])
        ->assertRedirect('http://coaching.test/admin/dashboard');

    Auth::guard('platform_admin')->logout();
    $this->post('http://coaching.test/admin/login', ['email' => $admin->email, 'password' => 'correct-horse-battery']);

    $this->post('http://coaching.test/admin/two-factor', ['code' => $code])
        ->assertSessionHasErrors('code');
    expect(Auth::guard('platform_admin')->guest())->toBeTrue();
});

test('the 2FA setup page shows a fresh secret and an otpauth URI to scan (positive control: the page renders for an admin)', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/two-factor/setup')
        ->assertOk()
        ->assertSee('otpauth://totp/', false);
});

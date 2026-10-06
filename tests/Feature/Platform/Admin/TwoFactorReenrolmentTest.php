<?php

use App\Models\AdminAuditLog;
use App\Models\PlatformAdmin;
use App\Support\Auth\Totp;

/**
 * Batch 1 (F): an admin who already has 2FA cannot silently replace the secret and recovery codes.
 * Replacement needs a valid current TOTP or recovery code; every enrolment and replacement is audited.
 */
const B1F_OLD_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
const B1F_NEW_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function b1fEnrolledAdmin(): PlatformAdmin
{
    $admin = PlatformAdmin::factory()->create();
    $admin->forceFill(['totp_secret' => B1F_OLD_SECRET, 'totp_enabled_at' => now(), 'totp_last_step' => null])->save();

    return $admin;
}

test('replacing an enrolled secret without a current code is refused and the old secret stays', function () {
    $admin = b1fEnrolledAdmin();

    $this->actingAs($admin, 'platform_admin')
        ->withSession(['admin_2fa_setup_secret' => B1F_NEW_SECRET])
        ->post('http://coaching.test/admin/two-factor/setup', ['code' => Totp::code(B1F_NEW_SECRET, time())]);

    expect($admin->fresh()->totp_secret)->toBe(B1F_OLD_SECRET);
});

test('replacing an enrolled secret with a wrong current code is refused, and the failure counts toward the lock', function () {
    $admin = b1fEnrolledAdmin();

    $this->actingAs($admin, 'platform_admin')
        ->withSession(['admin_2fa_setup_secret' => B1F_NEW_SECRET])
        ->post('http://coaching.test/admin/two-factor/setup', [
            'code' => Totp::code(B1F_NEW_SECRET, time()),
            'current_code' => '000000',
        ]);

    expect($admin->fresh()->totp_secret)->toBe(B1F_OLD_SECRET)
        ->and(AdminAuditLog::where('admin_id', $admin->id)->where('action', 'two_factor_failed')->count())->toBe(1);
});

test('positive control: replacing with a correct current code succeeds (functional)', function () {
    $admin = b1fEnrolledAdmin();

    $this->actingAs($admin, 'platform_admin')
        ->withSession(['admin_2fa_setup_secret' => B1F_NEW_SECRET])
        ->post('http://coaching.test/admin/two-factor/setup', [
            'code' => Totp::code(B1F_NEW_SECRET, time()),
            'current_code' => Totp::code(B1F_OLD_SECRET, time()),
        ])
        ->assertRedirect('/admin/dashboard');

    expect($admin->fresh()->totp_secret)->toBe(B1F_NEW_SECRET);
});

test('positive control: first-time enrolment works without a current code (functional)', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->withSession(['admin_2fa_setup_secret' => B1F_NEW_SECRET])
        ->post('http://coaching.test/admin/two-factor/setup', ['code' => Totp::code(B1F_NEW_SECRET, time())])
        ->assertRedirect('/admin/dashboard');

    expect($admin->fresh()->totp_secret)->toBe(B1F_NEW_SECRET);
});

test('a replacement writes a two_factor_replaced audit row', function () {
    $admin = b1fEnrolledAdmin();

    $this->actingAs($admin, 'platform_admin')
        ->withSession(['admin_2fa_setup_secret' => B1F_NEW_SECRET])
        ->post('http://coaching.test/admin/two-factor/setup', [
            'code' => Totp::code(B1F_NEW_SECRET, time()),
            'current_code' => Totp::code(B1F_OLD_SECRET, time()),
        ]);

    expect(AdminAuditLog::where('admin_id', $admin->id)->where('action', 'two_factor_replaced')->count())->toBe(1);
});

test('a first-time enrolment writes a two_factor_enrolled audit row', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->withSession(['admin_2fa_setup_secret' => B1F_NEW_SECRET])
        ->post('http://coaching.test/admin/two-factor/setup', ['code' => Totp::code(B1F_NEW_SECRET, time())]);

    expect(AdminAuditLog::where('admin_id', $admin->id)->where('action', 'two_factor_enrolled')->count())->toBe(1);
});

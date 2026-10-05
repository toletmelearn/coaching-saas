<?php

use App\Models\AdminAuditLog;
use App\Models\PlatformAdmin;
use App\Support\Auth\Totp;

/**
 * In production an admin without a TOTP secret is sent to enrol before reaching any admin page,
 * across every admin route group: the original admin pages and the Phase 13 control panel.
 * Outside production the same unenrolled admin is not redirected.
 */
test('in production an unenrolled admin is redirected to 2FA setup from every admin page group (positive control: an enrolled admin gets 200 on each)', function () {
    $pages = ['/admin/dashboard', '/admin/institutes', '/admin/health', '/admin/backups'];

    $unenrolled = PlatformAdmin::factory()->create();
    $enrolled = PlatformAdmin::factory()->create();
    $enrolled->forceFill(['totp_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'totp_enabled_at' => now()])->save();

    $originalEnv = app()['env'];
    app()['env'] = 'production';

    try {
        foreach ($pages as $page) {
            $this->actingAs($enrolled, 'platform_admin')
                ->get("http://coaching.test{$page}")
                ->assertOk();

            freshRequestCycle();

            $this->actingAs($unenrolled, 'platform_admin')
                ->get("http://coaching.test{$page}")
                ->assertRedirect('http://coaching.test/admin/two-factor/setup');

            freshRequestCycle();
        }
    } finally {
        app()['env'] = $originalEnv;
    }
});

test('a 2FA login writes the same audit entry as a password-only login (positive control: the password-only entry exists)', function () {
    $plain = PlatformAdmin::factory()->create(['password' => 'correct-horse-battery']);
    $this->post('http://coaching.test/admin/login', ['email' => $plain->email, 'password' => 'correct-horse-battery'])
        ->assertRedirect('http://coaching.test/admin/dashboard');

    $twoFactor = PlatformAdmin::factory()->create(['password' => 'correct-horse-battery']);
    $twoFactor->forceFill(['totp_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'totp_enabled_at' => now()])->save();
    $this->post('http://coaching.test/admin/login', ['email' => $twoFactor->email, 'password' => 'correct-horse-battery']);
    $this->post('http://coaching.test/admin/two-factor', [
        'code' => Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', time()),
    ])->assertRedirect('http://coaching.test/admin/dashboard');

    $plainEntry = AdminAuditLog::where('admin_id', $plain->id)->where('action', 'login')->first();
    $twoFactorEntry = AdminAuditLog::where('admin_id', $twoFactor->id)->where('action', 'login')->first();

    expect($plainEntry)->not->toBeNull()
        ->and($twoFactorEntry)->not->toBeNull()
        ->and($twoFactorEntry->action)->toBe($plainEntry->action)
        ->and($twoFactorEntry->ip_address)->toBe($plainEntry->ip_address)
        ->and($twoFactorEntry->ip_address)->not->toBeNull();
});

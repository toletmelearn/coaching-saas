<?php

use App\Models\PlatformAdmin;
use App\Support\Auth\Totp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 hardening on top of the TOTP challenge: the secret is encrypted at rest, recovery
 * codes are stored hashed and are single-use, there is a reset command, a pending-2FA state
 * expires after 5 minutes, challenge attempts are limited to 5 per minute per admin, and in
 * production an admin without a secret must enrol before reaching any other page.
 */
const P16H_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

function p16hEnrolled(string $password = 'correct-horse-battery'): PlatformAdmin
{
    expect(Schema::hasColumn('platform_admins', 'totp_secret'))->toBeTrue();
    $admin = PlatformAdmin::factory()->create(['password' => $password]);
    $admin->forceFill(['totp_secret' => P16H_SECRET, 'totp_enabled_at' => now()])->save();

    return $admin;
}

function p16hPassword(PlatformAdmin $admin): void
{
    test()->post('http://coaching.test/admin/login', ['email' => $admin->email, 'password' => 'correct-horse-battery']);
}

test('the TOTP secret is encrypted at rest (positive control: the model reads back the plain secret)', function () {
    $admin = p16hEnrolled();

    $raw = DB::table('platform_admins')->where('id', $admin->id)->value('totp_secret');

    expect($raw)->not->toBeNull()
        ->and($raw)->not->toBe(P16H_SECRET)
        ->and($admin->fresh()->totp_secret)->toBe(P16H_SECRET);
});

test('recovery codes are stored hashed and each one works exactly once (positive control: the setup page shows them once)', function () {
    $admin = PlatformAdmin::factory()->create(['password' => 'correct-horse-battery']);

    $page = $this->actingAs($admin, 'platform_admin')->get('http://coaching.test/admin/two-factor/setup');
    $page->assertOk();
    preg_match('/secret=([A-Z2-7]{32})/', $page->getContent(), $m);
    expect($m[1] ?? null)->not->toBeNull();

    $setup = $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/two-factor/setup', ['code' => Totp::code($m[1], time())]);
    $setup->assertRedirect();

    $codes = $setup->getSession()->get('recovery_codes');
    expect($codes)->toHaveCount(8);

    $stored = DB::table('platform_admins')->where('id', $admin->id)->value('recovery_codes');
    expect($stored)->not->toContain($codes[0]);
    expect(Hash::check($codes[0], json_decode($stored, true)[0]))->toBeTrue();

    // Single use: the first recovery code completes a login, the same code again does not.
    freshRequestCycle();
    p16hPassword($admin->fresh());
    $this->post('http://coaching.test/admin/two-factor', ['code' => $codes[0]])
        ->assertRedirect('http://coaching.test/admin/dashboard');

    Auth::guard('platform_admin')->logout();
    freshRequestCycle();
    p16hPassword($admin->fresh());
    $this->post('http://coaching.test/admin/two-factor', ['code' => $codes[0]])
        ->assertSessionHasErrors('code');
    expect(Auth::guard('platform_admin')->guest())->toBeTrue();
});

test('the platform-admin:reset-2fa command clears the secret and recovery codes (positive control: they exist before)', function () {
    $admin = p16hEnrolled();

    expect(array_key_exists('platform-admin:reset-2fa', Artisan::all()))->toBeTrue();
    expect($admin->fresh()->totp_secret)->not->toBeNull();

    Artisan::call('platform-admin:reset-2fa', ['email' => $admin->email]);

    expect($admin->fresh()->totp_secret)->toBeNull()
        ->and($admin->fresh()->totp_enabled_at)->toBeNull();
});

test('the pending 2FA state expires after 5 minutes (positive control: a code within 5 minutes works)', function () {
    $admin = p16hEnrolled();

    p16hPassword($admin);
    $this->travel(4)->minutes();
    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P16H_SECRET, time())])
        ->assertRedirect('http://coaching.test/admin/dashboard');

    Auth::guard('platform_admin')->logout();
    freshRequestCycle();
    p16hPassword($admin);
    $this->travel(6)->minutes();

    $this->post('http://coaching.test/admin/two-factor', ['code' => Totp::code(P16H_SECRET, time())])
        ->assertSessionHasErrors('code');
    expect(Auth::guard('platform_admin')->guest())->toBeTrue();
});

test('challenge attempts are limited to 5 per minute per admin (positive control: the first wrong code is an ordinary error)', function () {
    $admin = p16hEnrolled();
    p16hPassword($admin);

    $this->post('http://coaching.test/admin/two-factor', ['code' => '000000'])
        ->assertSessionHasErrors('code')
        ->assertStatus(302);

    for ($i = 0; $i < 4; $i++) {
        $this->post('http://coaching.test/admin/two-factor', ['code' => '000000']);
    }

    $this->post('http://coaching.test/admin/two-factor', ['code' => '000000'])->assertStatus(429);
});

test('in production an admin without a secret is sent to enrol before any other admin page (positive control: the same page is reachable outside production)', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/dashboard')
        ->assertOk();

    $originalEnv = app()['env'];
    app()['env'] = 'production';

    try {
        $this->actingAs($admin, 'platform_admin')
            ->get('http://coaching.test/admin/dashboard')
            ->assertRedirect('http://coaching.test/admin/two-factor/setup');
    } finally {
        app()['env'] = $originalEnv;
    }
});

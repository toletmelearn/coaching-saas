<?php

use App\Models\PlatformAdmin;
use App\Support\Admin\ArtisanRunner;
use App\Support\Admin\EnvFile;
use App\Support\Preflight\PreflightChecks;

/**
 * Phase 13 feature B — /admin/health renders the AppPreflightCommand checklist
 * through the same PreflightChecks code, with an APP_DEBUG-gated Fix button.
 * The Fix value comes from the server-side fix map (never the request), the
 * .env write goes through the injected EnvFile, and the action is audit-logged.
 */
test('the health page renders every preflight check', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/health');

    $response->assertOk();
    $response->assertSee(__('platform.admin.health.heading'));
    $response->assertSee('Secure session cookies');
    $response->assertSee('Database reachable');
    $response->assertSee('GD FreeType');

    // 13 fixed checks plus one row per configured writable path.
    $response->assertViewHas('outcomes', function (array $outcomes): bool {
        $ids = array_column($outcomes, 'id');

        return count($outcomes) >= 13
            && in_array('debug', $ids, true)
            && in_array('video_driver', $ids, true)
            && in_array('jitsi', $ids, true);
    });
});

test('fix buttons are hidden when APP_DEBUG is off and the endpoint refuses', function () {
    config(['app.debug' => false]);

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/health')
        ->assertOk()
        ->assertDontSee(__('platform.admin.health.fix_note'))
        ->assertDontSee(__('platform.admin.health.fix'));

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/health/fix', ['check' => 'debug'])
        ->assertForbidden();
});

test('fixing a check rewrites .env through the injected writer, clears config and audits', function () {
    config(['app.debug' => true]);

    $tmp = tempnam(sys_get_temp_dir(), 'p13env');
    file_put_contents($tmp, "APP_NAME=Coaching\nAPP_DEBUG=true\nSESSION_LIFETIME=43200\n");

    $this->app->bind(EnvFile::class, fn () => new EnvFile($tmp));

    $runner = new class extends ArtisanRunner
    {
        /** @var list<array{0: string, 1: array<string, mixed>}> */
        public array $calls = [];

        public function call(string $command, array $parameters = []): array
        {
            $this->calls[] = [$command, $parameters];

            return ['code' => 0, 'output' => ''];
        }
    };
    $this->app->bind(ArtisanRunner::class, fn () => $runner);

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/health/fix', ['check' => 'debug'])
        ->assertRedirect('http://coaching.test/admin/health')
        ->assertSessionHas('status');

    $content = file_get_contents($tmp);
    expect($content)->toContain('APP_DEBUG=false');
    expect($content)->toContain('APP_NAME=Coaching');
    expect($content)->toContain('SESSION_LIFETIME=43200');
    expect($content)->not->toContain('APP_DEBUG=true');

    expect($runner->calls)->toBe([['config:clear', []]]);

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'update_setting',
        'target_type' => 'health_fix:APP_DEBUG',
        'admin_id' => $admin->id,
    ]);

    @unlink($tmp);
});

test('fix refuses passing checks and unknown check ids', function () {
    config(['app.debug' => true]);

    $admin = PlatformAdmin::factory()->create();

    // 'app_key' passes on any bootable install (its fix is always null);
    // 'bogus' never exists. Both are 404, not silent no-ops.
    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/health/fix', ['check' => 'app_key'])
        ->assertNotFound();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/health/fix', ['check' => 'bogus-check'])
        ->assertNotFound();
});

test('fix never trusts the client for the value it writes', function () {
    config(['app.debug' => true]);

    $tmp = tempnam(sys_get_temp_dir(), 'p13env');
    file_put_contents($tmp, "APP_DEBUG=true\n");

    $this->app->bind(EnvFile::class, fn () => new EnvFile($tmp));

    $admin = PlatformAdmin::factory()->create();

    // Extra request fields (the value, the key) are ignored — only the check id
    // is read, the value comes from PreflightChecks' server-side fix map.
    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/health/fix', [
            'check' => 'debug',
            'key' => 'APP_KEY',
            'value' => 'stolen-from-client',
        ])
        ->assertRedirect('http://coaching.test/admin/health');

    $content = file_get_contents($tmp);
    expect($content)->toContain('APP_DEBUG=false');
    expect($content)->not->toContain('stolen-from-client');
    expect($content)->not->toContain('APP_KEY=');

    @unlink($tmp);
});

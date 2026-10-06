<?php

use App\Http\Controllers\Admin\SystemEnvController;
use App\Models\PlatformAdmin;
use App\Support\Admin\ArtisanRunner;
use App\Support\Admin\EnvFile;

/**
 * Phase 13 feature G — allowlisted .env editor. The file is written through
 * the injected EnvFile (temp file in tests — the real .env is never touched),
 * non-allowlisted keys and newline injection are refused, blank = keep, and
 * the cache buttons go through ArtisanRunner. Every change is audit-logged.
 */
test('the system page lists allowlisted keys with current values', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'p13env');
    file_put_contents($tmp, "APP_NAME=Coaching\nMAIL_HOST=mailhog\n");
    $this->app->bind(EnvFile::class, fn () => new EnvFile($tmp));

    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/settings/system');

    $response->assertOk();
    $response->assertSee('APP_NAME');
    $response->assertSee('Coaching');
    $response->assertViewHas('allowed', function (array $allowed): bool {
        return in_array('APP_NAME', $allowed, true)
            && in_array('SESSION_SECURE_COOKIE', $allowed, true)
            && ! in_array('APP_KEY', $allowed, true)
            && ! in_array('APP_DEBUG', $allowed, true)
            && ! in_array('DB_DATABASE', $allowed, true);
    });

    @unlink($tmp);
});

test('an allowlisted key is written to the env file and audited', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'p13env');
    file_put_contents($tmp, "# Coaching platform environment\nAPP_NAME=Coaching\nSESSION_LIFETIME=43200\nAPP_DEBUG=true\n");
    $this->app->bind(EnvFile::class, fn () => new EnvFile($tmp));

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/system', [
            'values' => [
                'APP_NAME' => 'New Brand',
                'SESSION_LIFETIME' => '120',
            ],
        ])
        ->assertRedirect('http://coaching.test/admin/settings/system')
        ->assertSessionHas('status');

    $content = file_get_contents($tmp);
    expect($content)->toContain('# Coaching platform environment');
    // Spaces without an apostrophe use single quotes (Dotenv-safe, no $-interpolation) —
    // pinned here so the quoting behaviour is intentional, not accidental.
    expect($content)->toContain("APP_NAME='New Brand'");
    expect($content)->toContain('SESSION_LIFETIME=120');
    expect($content)->toContain('APP_DEBUG=true');
    expect($content)->not->toContain('APP_NAME=Coaching');

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'update_setting',
        'target_type' => 'env:APP_NAME',
        'admin_id' => $admin->id,
    ]);
    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'update_setting',
        'target_type' => 'env:SESSION_LIFETIME',
        'admin_id' => $admin->id,
    ]);
});

test('a key outside the allowlist is refused and never written', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'p13env');
    file_put_contents($tmp, "APP_NAME=Coaching\n");
    $this->app->bind(EnvFile::class, fn () => new EnvFile($tmp));

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/system', [
            'values' => ['APP_KEY' => 'stolen'],
        ])
        ->assertSessionHasErrors('values');

    expect(file_get_contents($tmp))->toBe("APP_NAME=Coaching\n");
    $this->assertDatabaseCount('admin_audit_logs', 0);
});

test('a newline in a value is rejected as env injection', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'p13env');
    file_put_contents($tmp, "APP_NAME=Coaching\n");
    $this->app->bind(EnvFile::class, fn () => new EnvFile($tmp));

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/system', [
            'values' => ['APP_NAME' => "Line1\nAPP_DEBUG=true"],
        ])
        ->assertSessionHasErrors('values.APP_NAME');

    expect(file_get_contents($tmp))->toBe("APP_NAME=Coaching\n");
});

test('blank values keep the current entry and write nothing', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'p13env');
    file_put_contents($tmp, "APP_NAME=Coaching\n");
    $this->app->bind(EnvFile::class, fn () => new EnvFile($tmp));

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/system', [
            'values' => ['APP_NAME' => ''],
        ])
        ->assertRedirect('http://coaching.test/admin/settings/system');

    expect(file_get_contents($tmp))->toBe("APP_NAME=Coaching\n");
    $this->assertDatabaseCount('admin_audit_logs', 0);
});

test('clear cache and optimize run through ArtisanRunner and are audited', function () {
    $runner = new class extends ArtisanRunner
    {
        /** @var list<string> */
        public array $calls = [];

        public function call(string $command, array $parameters = []): array
        {
            $this->calls[] = $command;

            return ['code' => 0, 'output' => ''];
        }
    };
    $this->app->bind(ArtisanRunner::class, fn () => $runner);

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/system/clear-cache')
        ->assertRedirect('http://coaching.test/admin/settings/system')
        ->assertSessionHas('status');

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/system/optimize')
        ->assertRedirect('http://coaching.test/admin/settings/system')
        ->assertSessionHas('status');

    expect($runner->calls)->toBe(['optimize:clear', 'optimize']);

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'clear_cache', 'admin_id' => $admin->id]);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'optimize', 'admin_id' => $admin->id]);
});

test('the allowlist itself contains only the approved operational keys', function () {
    expect(SystemEnvController::ALLOWED)->toBe([
        'APP_NAME',
        'APP_URL',
        'MAIL_MAILER',
        'MAIL_HOST',
        'MAIL_PORT',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'SESSION_LIFETIME',
        'SESSION_SECURE_COOKIE',
        'VIDEO_DRIVER',
        'PLATFORM_DOMAIN',
        'TENANT_BASE_DOMAIN',
        'CENTRAL_DOMAINS',
    ]);
});

<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Config values that, together, make every check pass — used as the positive control
 * baseline, then each test breaks exactly one of them.
 */
function passingPreflightConfig(): array
{
    return [
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://demo.coaching.app',
        'tenancy.platform_domain' => 'coaching.app',
        'tenancy.tenant_base_domain' => 'coaching.app',
        'tenancy.central_domains' => ['coaching.app', 'platform.coaching.app'],
        'session.secure' => true,
        // Phase 16.1: production requires encrypted session payloads (SESSION_ENCRYPT=true).
        'session.encrypt' => true,
        // Host-only session cookies: null (.env.example) and '' (docs/DEPLOY.md's
        // `SESSION_DOMAIN=`) are both fine; anything else must fail preflight.
        'session.domain' => null,
        'preflight.writable_paths' => [storage_path()],
        'coaching.video_driver' => 'bunny',
        'services.bunny.account_api_key' => 'test-account-key',
        // Mocked rather than relying on the test runner's own PHP build actually having
        // GD/FreeType — see config/preflight.php.
        'preflight.gd_extension_loaded' => true,
        'preflight.gd_freetype_supported' => true,
    ];
}

test('passes when everything is configured correctly', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());

    $this->artisan('app:preflight')->assertSuccessful();
});

test('is a no-op outside production', function () {
    // Deliberately broken config — but since we're not in production, none of it matters.
    // Exit 0 here is the *default*: the loud failure (exit 2) only happens when the caller
    // asks for it with --require-production, which is what the deploy runbook now does
    // (docs/DEPLOY_RUNBOOK.md §6.1 / §10.12) and what the next test asserts. Without the
    // flag this informational no-op stays SUCCESS by design (SP-7's fix is opt-in).
    config(['app.debug' => true, 'app.key' => '']);

    $this->artisan('app:preflight')->assertSuccessful();
});

test('fails with exit code 2 outside production when --require-production is passed', function () {
    // Same deliberately broken config as the no-op test above — the flag is the only difference.
    config(['app.debug' => true, 'app.key' => '']);

    $this->artisan('app:preflight', ['--require-production' => true])
        ->expectsOutputToContain('this command was run with --require-production')
        // self::INVALID = 2, deliberately not 1 (FAILURE): exit 1 means a real preflight
        // check failed, exit 2 means APP_ENV isn't production at all — "you forgot APP_ENV=production".
        ->assertExitCode(2);
});

test('fails when APP_DEBUG is true', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['app.debug' => true]);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when APP_KEY is empty', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['app.key' => '']);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when APP_URL is not https', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['app.url' => 'http://demo.coaching.app']);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when the central/tenant base domain is missing or still the local default', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['tenancy.platform_domain' => 'coaching.test']);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when SESSION_SECURE_COOKIE is false', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['session.secure' => false]);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when a required path is not writable', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    // A path that does not exist is_writable()-false in a way that's portable across OSes,
    // unlike relying on chmod actually restricting the test runner's own user.
    config(['preflight.writable_paths' => [storage_path().'/this-path-does-not-exist-xyz']]);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when the database is unreachable', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());

    // Point the *default* connection name at a brand-new connection config (same host/
    // credentials, a database name that doesn't exist) rather than mutating the real test
    // connection in place — RefreshDatabase already has an open transaction on that real
    // connection object, and purging/reconfiguring it mid-test breaks its rollback at
    // teardown. The real connection's config is never touched, so it's untouched by this.
    $originalDefault = config('database.default');
    $originalConfig = config("database.connections.{$originalDefault}");

    config([
        'database.connections.preflight_test_unreachable' => array_merge($originalConfig, [
            'database' => 'this_database_does_not_exist_xyz',
        ]),
        'database.default' => 'preflight_test_unreachable',
    ]);

    $this->artisan('app:preflight')->assertFailed();

    config(['database.default' => $originalDefault]);
})->skip(fn () => DB::connection(config('database.default'))->getDriverName() !== 'mysql', 'Simulating an unreachable DB by database name only works reliably against a real MySQL/MariaDB connection.');

test('fails when a demo tenant exists', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    Tenant::factory()->create(['name' => 'Demo Institute']);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when the local demo platform admin account exists', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    PlatformAdmin::factory()->create(['email' => 'admin@coaching.test']);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when GD is not available', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    // A config flag (rather than calling extension_loaded('gd') directly) so this is
    // mockable without actually uninstalling the GD extension from the test runner.
    config(['preflight.gd_extension_loaded' => false]);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when the demo.localhost PWA-testing domain exists', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());

    $tenant = Tenant::factory()->create(['name' => 'Demo Institute local']);
    $tenant->domains()->create(['domain' => 'demo.localhost', 'type' => 'subdomain']);

    $this->artisan('app:preflight')->assertFailed();
});

// === SESSION_DOMAIN (docs/DEPLOY.md §3 always claimed this check existed) ===

test('passes when SESSION_DOMAIN is the empty string docs/DEPLOY.md tells you to use', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['session.domain' => '']);

    $this->artisan('app:preflight')->assertSuccessful();
});

test('fails when SESSION_DOMAIN widens the session cookie to a shared parent domain', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['session.domain' => '.coaching.app']);

    $this->artisan('app:preflight')->assertFailed();
});

// === VIDEO_DRIVER — code existed, but no test ever exercised either failing branch ===

test('fails when VIDEO_DRIVER is fake', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['coaching.video_driver' => 'fake']);

    $this->artisan('app:preflight')->assertFailed();
});

test('fails when VIDEO_DRIVER is bunny but the account API key is empty', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['services.bunny.account_api_key' => null]);

    $this->artisan('app:preflight')->assertFailed();
});

// === FreeType — the second branch of checkGdFreetype() had no failing test either ===

test('fails when GD is loaded but has no FreeType support', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['preflight.gd_freetype_supported' => false]);

    $this->artisan('app:preflight')->assertFailed();
});

// === MySQL/MariaDB version floor — the only preflight check with no failing-branch test (SP-9) ===

test('fails when the database server version is below the CHECK-constraint floor', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    // config/preflight.php seam (mirrors the GD one): pretends the server reports a version
    // below the 8.0.16 floor, so the branch is reachable without an actually-old server —
    // and without it, the SQLite driver gate would skip the check entirely.
    config(['preflight.forced_database_version' => '8.0.15']);

    $this->artisan('app:preflight', ['--require-production' => true])
        ->expectsOutputToContain('MySQL 8.0.15 is below the minimum 8.0.16')
        // exit 1 (FAILURE): a real preflight check failed — distinct from exit 2, which
        // means APP_ENV isn't production at all.
        ->assertExitCode(1);
});

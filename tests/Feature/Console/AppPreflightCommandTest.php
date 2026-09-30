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
    config(['app.debug' => true, 'app.key' => '']);

    $this->artisan('app:preflight')->assertSuccessful();
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

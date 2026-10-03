<?php

/**
 * Config values that make every preflight check pass, plus the live-class keys
 * under test. Deliberately a private copy of passingPreflightConfig() from
 * tests/Feature/Console/AppPreflightCommandTest.php: relying on a function
 * declared in another test file would couple this file to suite load order.
 */
function jitsiPreflightConfig(): array
{
    return [
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://demo.coaching.app',
        'tenancy.platform_domain' => 'coaching.app',
        'tenancy.tenant_base_domain' => 'coaching.app',
        'tenancy.central_domains' => ['coaching.app', 'platform.coaching.app'],
        'session.secure' => true,
        'session.domain' => null,
        'preflight.writable_paths' => [storage_path()],
        'coaching.video_driver' => 'bunny',
        'services.bunny.account_api_key' => 'test-account-key',
        'preflight.gd_extension_loaded' => true,
        'preflight.gd_freetype_supported' => true,
    ];
}

test('app:preflight refuses production without the JaaS keys when live classes are on, passes with them, and never needs them when off', function () {
    app()->instance('env', 'production');

    // Branch 1 — enabled but unconfigured: must refuse AND say Jitsi in the output
    config(jitsiPreflightConfig());
    config([
        'coaching.live_classes_enabled' => true,
        'services.jitsi.app_id' => null,
        'services.jitsi.app_secret' => null,
    ]);

    $this->artisan('app:preflight')
        ->expectsOutputToContain('Jitsi')
        ->assertFailed();

    // Branch 2 — enabled and configured: passes, and the check is visible
    config(jitsiPreflightConfig());
    config([
        'coaching.live_classes_enabled' => true,
        'services.jitsi.app_id' => 'jitsi-app-id',
        'services.jitsi.app_secret' => 'jitsi-app-secret',
    ]);

    $this->artisan('app:preflight')
        ->expectsOutputToContain('Jitsi')
        ->assertSuccessful();

    // Branch 3 — feature off: the JaaS keys are simply not required
    config(jitsiPreflightConfig());
    config([
        'coaching.live_classes_enabled' => false,
        'services.jitsi.app_id' => null,
        'services.jitsi.app_secret' => null,
    ]);

    $this->artisan('app:preflight')->assertSuccessful();
});

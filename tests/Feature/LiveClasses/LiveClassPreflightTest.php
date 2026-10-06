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
        // Phase 16.1: production requires encrypted session payloads (SESSION_ENCRYPT=true).
        'session.encrypt' => true,
        'session.domain' => null,
        'preflight.writable_paths' => [storage_path()],
        'coaching.video_driver' => 'bunny',
        'services.bunny.account_api_key' => 'test-account-key',
        'preflight.gd_extension_loaded' => true,
        'preflight.gd_freetype_supported' => true,
        'backup.backup.password' => 'test-archive-password',
    ];
}

test('app:preflight passes with live classes on and no JaaS keys (paste-URL approach needs no keys)', function () {
    app()->instance('env', 'production');

    // Live classes on, no JaaS keys — must pass (we no longer use JaaS).
    config(jitsiPreflightConfig());
    config([
        'coaching.live_classes_enabled' => true,
        'services.jitsi.app_id' => null,
        'services.jitsi.app_secret' => null,
    ]);

    $this->artisan('app:preflight')->assertSuccessful();
});

test('app:preflight passes with live classes off and no JaaS keys', function () {
    app()->instance('env', 'production');

    config(jitsiPreflightConfig());
    config([
        'coaching.live_classes_enabled' => false,
        'services.jitsi.app_id' => null,
        'services.jitsi.app_secret' => null,
    ]);

    $this->artisan('app:preflight')->assertSuccessful();
});

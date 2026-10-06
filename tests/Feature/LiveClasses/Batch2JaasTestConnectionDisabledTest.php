<?php

use App\Models\PlatformAdmin;
use App\Support\Admin\ServiceSettings;
use Illuminate\Support\Facades\Http;

/**
 * Batch 2.5 — JaaS test-connection route removed.
 *
 * The route POST /admin/settings/services/test-jitsi has been deleted entirely
 * (Batch 2.5). It returns 404 regardless of whether JaaS credentials are
 * configured. The Bunny endpoint is unaffected (positive control inside test 1).
 *
 * Both tests fail on old code because the old endpoint returned 302 (redirect),
 * not 404.
 */

test('2.5 the test-jitsi route no longer exists and returns 404', function () {
    Http::fake(['api.bunny.net/*' => Http::response(['libraries' => []], 200)]);

    $admin = PlatformAdmin::factory()->create();
    ServiceSettings::set('jitsi_app_id', 'test-app-id');
    ServiceSettings::set('jitsi_app_secret', 'test-app-secret');
    ServiceSettings::set('bunny_account_api_key', 'valid-bunny-key');

    // Positive control: the Bunny test is still a 302 redirect (unchanged).
    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services/test-bunny')
        ->assertRedirect();

    // Negative: JaaS test-connection route no longer exists.
    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services/test-jitsi')
        ->assertNotFound();
});

test('2.5 the test-jitsi route returns 404 even when JaaS keys are configured', function () {
    $admin = PlatformAdmin::factory()->create();
    ServiceSettings::set('jitsi_app_id', 'test-app-id');
    ServiceSettings::set('jitsi_app_secret', 'test-app-secret');

    // Positive control: keys are still stored and readable (settings system intact).
    expect(ServiceSettings::get('jitsi_app_id'))->toBe('test-app-id');

    // Negative: route returns 404 regardless of key presence.
    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services/test-jitsi')
        ->assertNotFound();
});

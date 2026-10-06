<?php

use App\Models\PlatformAdmin;
use App\Models\SystemSetting;
use App\Support\Admin\ServiceSettings;
use App\Support\LiveClasses\JitsiJwt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Phase 13 feature A — /admin/settings/services: encrypted at rest, secrets
 * never echoed back, live "test connection" buttons, audit rows per change.
 */
test('saving service settings encrypts secrets at rest and never renders them back', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services', [
            'bunny_account_api_key' => 'bunny-secret-key-123',
            'jitsi_app_id' => 'myjitsi-app',
            'jitsi_app_secret' => 'jitsi-secret-456',
        ])
        ->assertRedirect('http://coaching.test/admin/settings/services')
        ->assertSessionHas('status');

    // At rest: ciphertext, not plaintext. jitsi_app_id is a public identifier
    // (it appears in every join URL) and is stored plaintext by design.
    $bunnyRow = SystemSetting::query()->where('key', 'bunny_account_api_key')->firstOrFail();
    expect($bunnyRow->value['encrypted'] ?? false)->toBeTrue();
    expect(json_encode($bunnyRow->value))->not->toContain('bunny-secret-key-123');

    $secretRow = SystemSetting::query()->where('key', 'jitsi_app_secret')->firstOrFail();
    expect($secretRow->value['encrypted'] ?? false)->toBeTrue();
    expect(json_encode($secretRow->value))->not->toContain('jitsi-secret-456');

    $appIdRow = SystemSetting::query()->where('key', 'jitsi_app_id')->firstOrFail();
    expect(json_encode($appIdRow->value))->toContain('myjitsi-app');

    // Round trip: what consumers read is the decrypted plaintext.
    expect(ServiceSettings::get('bunny_account_api_key'))->toBe('bunny-secret-key-123');
    expect(ServiceSettings::get('jitsi_app_secret'))->toBe('jitsi-secret-456');

    // The form never echoes secrets back. The jitsi fields are removed from the
    // UI (Batch 2.5) so myjitsi-app no longer appears in the page source.
    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/settings/services')
        ->assertOk()
        ->assertDontSee('bunny-secret-key-123')
        ->assertDontSee('jitsi-secret-456')
        ->assertSee(__('platform.admin.services.configured'));

    // One audit row per key that actually changed.
    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'update_setting',
        'target_type' => 'setting:bunny_account_api_key',
        'admin_id' => $admin->id,
    ]);
    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'update_setting',
        'target_type' => 'setting:jitsi_app_secret',
        'admin_id' => $admin->id,
    ]);
    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'update_setting',
        'target_type' => 'setting:jitsi_app_id',
        'admin_id' => $admin->id,
    ]);
});

test('blank secret fields keep the stored value', function () {
    $admin = PlatformAdmin::factory()->create();
    ServiceSettings::set('bunny_account_api_key', 'original-key');

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services', [
            'bunny_account_api_key' => '',
            'jitsi_app_id' => 'only-this-one',
        ])
        ->assertRedirect('http://coaching.test/admin/settings/services');

    expect(ServiceSettings::get('bunny_account_api_key'))->toBe('original-key');
    expect(ServiceSettings::get('jitsi_app_id'))->toBe('only-this-one');

    // No audit row for the key that was not touched.
    $this->assertDatabaseMissing('admin_audit_logs', [
        'action' => 'update_setting',
        'target_type' => 'setting:bunny_account_api_key',
    ]);
});

test('the bunny test connection calls the bunny api with the saved key', function () {
    Http::fake(['api.bunny.net/*' => Http::response(['libraries' => []], 200)]);

    $admin = PlatformAdmin::factory()->create();
    ServiceSettings::set('bunny_account_api_key', 'test-key-abc');

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services/test-bunny')
        ->assertRedirect('http://coaching.test/admin/settings/services')
        ->assertSessionHas('bunny_test', 'ok');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.bunny.net/videolibrary'
        && in_array('test-key-abc', (array) $request->header('AccessKey'), true));
});

test('the bunny test connection reports a rejected key', function () {
    Http::fake(['api.bunny.net/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    $admin = PlatformAdmin::factory()->create();
    ServiceSettings::set('bunny_account_api_key', 'wrong-key');

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services/test-bunny')
        ->assertSessionHas('bunny_test', 'fail');
});

test('the bunny test connection reports an unreachable api without crashing', function () {
    Http::fake(['api.bunny.net/*' => fn () => throw new ConnectionException('network down')]);

    $admin = PlatformAdmin::factory()->create();
    ServiceSettings::set('bunny_account_api_key', 'any-key');

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services/test-bunny')
        ->assertSessionHas('bunny_test', 'fail');
});

test('the bunny test button asks for a key when none is configured', function () {
    config(['services.bunny.account_api_key' => null]);

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services/test-bunny')
        ->assertSessionHas('bunny_test', 'missing');
});

test('2.5 the test-jitsi route no longer exists and returns 404', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/settings/services/test-jitsi')
        ->assertNotFound();
});

test('a token signed with one secret does not verify against another', function () {
    $token = JitsiJwt::make('app-id', 'right-secret', 'A', 'a@example.test', 2);

    expect(JitsiJwt::verify($token, 'right-secret'))->toBeTrue();
    expect(JitsiJwt::verify($token, 'wrong-secret'))->toBeFalse();
    expect(JitsiJwt::verify('not-a-token', 'right-secret'))->toBeFalse();
});

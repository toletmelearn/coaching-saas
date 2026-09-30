<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Schema;

test('no ip-shaped column exists on user_devices', function () {
    // Positive control: the table must actually exist for the two absent-column
    // assertions below to mean anything — otherwise both would trivially (and
    // meaninglessly) pass against a table that was never created.
    expect(Schema::hasTable('user_devices'))->toBeTrue();

    expect(Schema::hasColumn('user_devices', 'ip'))->toBeFalse();
    expect(Schema::hasColumn('user_devices', 'ip_address'))->toBeFalse();
});

test('a login from a given IP never stores that IP anywhere on the device row', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->post("http://{$domain}/login", [
        'identifier' => $student->email,
        'password' => 'password',
    ], ['REMOTE_ADDR' => '203.0.113.7']);

    $stored = inTenant($tenant, fn () => UserDevice::where('user_id', $student->id)->first());

    expect($stored)->not->toBeNull();
    foreach ($stored->getAttributes() as $key => $value) {
        expect((string) $value)->not->toContain('203.0.113.7');
    }
});

test('the user agent is truncated to 255 characters', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $longUserAgent = str_repeat('A', 400);

    $this->withHeaders(['User-Agent' => $longUserAgent])
        ->post("http://{$domain}/login", [
            'identifier' => $student->email,
            'password' => 'password',
        ]);

    $stored = inTenant($tenant, fn () => UserDevice::where('user_id', $student->id)->first());

    expect(strlen((string) $stored->user_agent))->toBeLessThanOrEqual(255);
});

test('devices:prune removes only device rows not seen for 60+ days', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'tenant-a.coaching.test', 'type' => 'subdomain']);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    [$staleId, $recentId] = inTenant($tenant, function () use ($student) {
        $stale = UserDevice::create(['user_id' => $student->id, 'device_id' => bin2hex(random_bytes(16)), 'label' => 'Chrome on Android']);
        $stale->forceFill(['last_seen_at' => now()->subDays(61)])->save();

        $recent = UserDevice::create(['user_id' => $student->id, 'device_id' => bin2hex(random_bytes(16)), 'label' => 'Safari on iOS']);
        $recent->forceFill(['last_seen_at' => now()->subDays(10)])->save();

        return [$stale->id, $recent->id];
    });

    $this->artisan('devices:prune')->assertSuccessful();

    inTenant($tenant, function () use ($staleId, $recentId) {
        expect(UserDevice::find($staleId))->toBeNull();
        expect(UserDevice::find($recentId))->not->toBeNull();
    });
});

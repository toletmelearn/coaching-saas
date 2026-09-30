<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function deviceTenantFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = strtolower(Str::random(8)).'.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    return [$tenant, $domain, $student];
}

// === Uniqueness ===

test('a device row is unique per (tenant, user, device_id)', function () {
    [$tenant, , $student] = deviceTenantFixture();

    inTenant($tenant, function () use ($tenant, $student) {
        // Positive control: a different device_id for the same student succeeds.
        expect(UserDevice::create([
            'user_id' => $student->id,
            'device_id' => str_repeat('a', 64),
            'label' => 'Chrome on Android',
        ]))->not->toBeNull();

        expect(fn () => DB::table('user_devices')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $student->id,
            'device_id' => str_repeat('a', 64),
            'label' => 'Chrome on Android',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

test('the composite FK rejects a device row for another tenant\'s user', function () {
    [$tenantA, , $studentA] = deviceTenantFixture();
    [$tenantB, , $studentB] = deviceTenantFixture();

    // Positive control: a raw insert with tenantB's own user_id succeeds — proves the
    // QueryException asserted below is actually caused by the cross-tenant user_id, not
    // by some unrelated schema problem (e.g. the table not existing at all).
    expect(fn () => DB::table('user_devices')->insert([
        'tenant_id' => $tenantB->id,
        'user_id' => $studentB->id,
        'device_id' => str_repeat('e', 64),
        'label' => 'Unknown device',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->not->toThrow(QueryException::class);

    expect(fn () => DB::table('user_devices')->insert([
        'tenant_id' => $tenantB->id,
        'user_id' => $studentA->id,
        'device_id' => str_repeat('b', 64),
        'label' => 'Unknown device',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the same device_id string registered under two different tenants never interferes', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $tenantA->domains()->create(['domain' => 'tenant-a.coaching.test', 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => 'tenant-b.coaching.test', 'type' => 'subdomain']);

    $studentA = inTenant($tenantA, fn () => User::factory()->student()->create());
    $studentB = inTenant($tenantB, fn () => User::factory()->student()->create());

    inTenant($tenantA, fn () => UserDevice::create([
        'user_id' => $studentA->id,
        'device_id' => str_repeat('c', 64),
        'label' => 'Chrome on Android',
    ]));

    inTenant($tenantB, fn () => UserDevice::create([
        'user_id' => $studentB->id,
        'device_id' => str_repeat('c', 64),
        'label' => 'Chrome on Android',
    ]));

    inTenant($tenantA, fn () => expect(UserDevice::count())->toBe(1));
    inTenant($tenantB, fn () => expect(UserDevice::count())->toBe(1));
});

// === Mass assignment guards ===

test('guarded device fields cannot be set via mass assignment', function () {
    [$tenant, , $student] = deviceTenantFixture();
    $otherTenant = Tenant::factory()->create();
    $otherStudent = inTenant($tenant, fn () => User::factory()->student()->create());

    inTenant($tenant, function () use ($tenant, $otherTenant, $otherStudent) {
        $device = UserDevice::create([
            'label' => 'Chrome on Android',
            'user_agent' => 'test-agent',
            'tenant_id' => $otherTenant->id,
            'user_id' => $otherStudent->id,
            'device_id' => str_repeat('d', 64),
            'revoked_at' => now(),
            'revoked_reason' => 'owner',
            'notified_at' => now(),
            'first_seen_at' => now()->subYear(),
            'last_seen_at' => now()->subYear(),
        ]);

        expect($device->tenant_id)->toBe($tenant->id)
            ->and($device->revoked_at)->toBeNull()
            ->and($device->revoked_reason)->toBeNull()
            ->and($device->notified_at)->toBeNull();
    });
});

// === max_devices_per_student validation ===

test('max_devices_per_student accepts 1, 2 or 3 via the settings page', function (int $value) {
    [$tenant, $domain] = deviceTenantFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/settings", [
            'name' => 'Institute',
            'max_devices_per_student' => $value,
        ])
        ->assertRedirect();

    inTenant($tenant, fn () => $tenant->refresh());
    expect($tenant->max_devices_per_student)->toBe($value);
})->with([1, 2, 3]);

test('max_devices_per_student rejects 0 and 4', function (int $value) {
    [$tenant, $domain] = deviceTenantFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/settings", [
            'name' => 'Institute',
            'max_devices_per_student' => $value,
        ])
        ->assertSessionHasErrors('max_devices_per_student');
})->with([0, 4]);

test('max_devices_per_student is not settable through any other request', function () {
    [$tenant, $domain, $student] = deviceTenantFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    inTenant($tenant, fn () => $tenant->forceFill(['max_devices_per_student' => 1])->save());

    // Attempting to smuggle it through the user-management endpoint, which has nothing
    // to do with tenant-wide settings.
    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/users/{$student->id}", [
            'name' => 'Renamed',
            'max_devices_per_student' => 3,
        ]);

    inTenant($tenant, fn () => $tenant->refresh());
    expect($tenant->max_devices_per_student)->toBe(1);
});

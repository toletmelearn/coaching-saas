<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;

function studentWithTwoDevices(Tenant $tenant): array
{
    $deviceA = bin2hex(random_bytes(16));
    $deviceB = bin2hex(random_bytes(16));

    $student = inTenant($tenant, function () use ($deviceA, $deviceB) {
        $student = User::factory()->student()->create();
        UserDevice::create(['user_id' => $student->id, 'device_id' => $deviceA, 'label' => 'Chrome on Android'])
            ->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();
        UserDevice::create(['user_id' => $student->id, 'device_id' => $deviceB, 'label' => 'Safari on iOS'])
            ->forceFill(['last_seen_at' => now()])->save();

        return $student;
    });

    return [$student, $deviceA, $deviceB];
}

test('an owner resetting a student\'s password revokes all of that student\'s devices', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    [$student, $deviceA, $deviceB] = studentWithTwoDevices($tenant);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/reset-password")
        ->assertRedirect();

    inTenant($tenant, function () use ($student, $deviceA, $deviceB) {
        $a = UserDevice::where('user_id', $student->id)->where('device_id', $deviceA)->firstOrFail();
        $b = UserDevice::where('user_id', $student->id)->where('device_id', $deviceB)->firstOrFail();

        expect($a->revoked_at)->not->toBeNull()->and($a->revoked_reason->value)->toBe('password_reset');
        expect($b->revoked_at)->not->toBeNull()->and($b->revoked_reason->value)->toBe('password_reset');
    });
});

test('a student changing their own password revokes every other device but keeps the current one', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    [$student, $currentDeviceId, $otherDeviceId] = studentWithTwoDevices($tenant);

    $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $currentDeviceId)
        ->post("http://{$domain}/auth/change-password", [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertRedirect();

    inTenant($tenant, function () use ($student, $currentDeviceId, $otherDeviceId) {
        $current = UserDevice::where('user_id', $student->id)->where('device_id', $currentDeviceId)->firstOrFail();
        $other = UserDevice::where('user_id', $student->id)->where('device_id', $otherDeviceId)->firstOrFail();

        expect($current->revoked_at)->toBeNull();
        expect($other->revoked_at)->not->toBeNull();
        expect($other->revoked_reason->value)->toBe('password_changed');
    });
});

test('disabling a student revokes all of their devices', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    [$student, $deviceA, $deviceB] = studentWithTwoDevices($tenant);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/disable")
        ->assertRedirect();

    inTenant($tenant, function () use ($student, $deviceA, $deviceB) {
        $a = UserDevice::where('user_id', $student->id)->where('device_id', $deviceA)->firstOrFail();
        $b = UserDevice::where('user_id', $student->id)->where('device_id', $deviceB)->firstOrFail();

        expect($a->revoked_at)->not->toBeNull()->and($a->revoked_reason->value)->toBe('disabled');
        expect($b->revoked_at)->not->toBeNull()->and($b->revoked_reason->value)->toBe('disabled');
    });
});

test('a normal logout revokes only that device, with reason logout, freeing the slot', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    inTenant($tenant, fn () => $tenant->forceFill(['max_devices_per_student' => 1])->save());
    [$student, $deviceA] = studentWithTwoDevices($tenant);

    $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceA)
        ->post("http://{$domain}/logout")
        ->assertRedirect();

    inTenant($tenant, function () use ($student, $deviceA) {
        $a = UserDevice::where('user_id', $student->id)->where('device_id', $deviceA)->firstOrFail();

        expect($a->revoked_at)->not->toBeNull();
        expect($a->revoked_reason->value)->toBe('logout');
    });
});

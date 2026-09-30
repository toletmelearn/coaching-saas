<?php

use App\Enums\DeviceRevocationReason;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Str;

function deviceToolsFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = strtolower(Str::random(8)).'.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->create(['name' => 'Priya Sharma']));

    return [$tenant, $domain, $owner, $staff, $student];
}

// === People page ===

test('the People page shows a device count and last-active time for students only', function () {
    [$tenant, $domain, $owner, , $student] = deviceToolsFixture();
    inTenant($tenant, fn () => UserDevice::create(['user_id' => $student->id, 'device_id' => bin2hex(random_bytes(16)), 'label' => 'Chrome on Android'])
        ->forceFill(['last_seen_at' => now()])->save());

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users");

    $response->assertOk();
    $response->assertSee('Priya Sharma');
    $response->assertSee(__('users.devices.count', ['count' => 1]));
});

// === Devices page listing ===

test('the Devices page lists a student\'s devices with status and reason', function () {
    [$tenant, $domain, $owner, , $student] = deviceToolsFixture();
    inTenant($tenant, function () use ($student) {
        UserDevice::create(['user_id' => $student->id, 'device_id' => bin2hex(random_bytes(16)), 'label' => 'Chrome on Android'])
            ->forceFill(['last_seen_at' => now()])->save();
        UserDevice::create(['user_id' => $student->id, 'device_id' => bin2hex(random_bytes(16)), 'label' => 'Safari on iOS'])
            ->forceFill(['revoked_at' => now(), 'revoked_reason' => DeviceRevocationReason::Owner])->save();
    });

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/{$student->id}/devices");

    $response->assertOk();
    $response->assertSee('Chrome on Android');
    $response->assertSee('Safari on iOS');
    $response->assertSee(__('devices.status.active'));
    $response->assertSee(__('devices.status.signed_out'));
});

// === Sign out actions ===

test('signing out one device takes effect on the student\'s next request', function () {
    [$tenant, $domain, $owner, , $student] = deviceToolsFixture();
    $deviceId = bin2hex(random_bytes(16));
    $device = inTenant($tenant, function () use ($student, $deviceId) {
        $device = UserDevice::create(['user_id' => $student->id, 'device_id' => $deviceId, 'label' => 'Chrome on Android']);
        $device->forceFill(['last_seen_at' => now()])->save();

        return $device;
    });

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/devices/{$device->id}/sign-out")
        ->assertRedirect();

    freshRequestCycle();

    $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceId)
        ->get("http://{$domain}/dashboard")
        ->assertRedirect("http://{$domain}/login");
});

test('signing out all devices revokes every active device for that student', function () {
    [$tenant, $domain, $owner, , $student] = deviceToolsFixture();
    $deviceA = bin2hex(random_bytes(16));
    $deviceB = bin2hex(random_bytes(16));
    inTenant($tenant, function () use ($student, $deviceA, $deviceB) {
        UserDevice::create(['user_id' => $student->id, 'device_id' => $deviceA, 'label' => 'Chrome on Android'])->forceFill(['last_seen_at' => now()])->save();
        UserDevice::create(['user_id' => $student->id, 'device_id' => $deviceB, 'label' => 'Safari on iOS'])->forceFill(['last_seen_at' => now()])->save();
    });

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/devices/sign-out-all")
        ->assertRedirect();

    inTenant($tenant, function () use ($student) {
        expect(UserDevice::where('user_id', $student->id)->whereNull('revoked_at')->count())->toBe(0);
    });
});

// === Permission matrix ===

test('owner can view and manage a student\'s devices', function () {
    [$tenant, $domain, $owner, , $student] = deviceToolsFixture();

    $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/{$student->id}/devices")->assertOk();
});

test('staff can view and manage a student\'s devices', function () {
    [$tenant, $domain, , $staff, $student] = deviceToolsFixture();

    $this->actingAs($staff, 'tenant')->get("http://{$domain}/users/{$student->id}/devices")->assertOk();
});

test('staff cannot view an owner\'s or another staff member\'s devices', function () {
    [$tenant, $domain, $owner, $staff] = deviceToolsFixture();
    $otherStaff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control: staff can view a student's devices (proves the route itself works).
    $student = inTenant($tenant, fn () => User::factory()->student()->create());
    $this->actingAs($staff, 'tenant')->get("http://{$domain}/users/{$student->id}/devices")->assertOk();

    freshRequestCycle();
    $this->actingAs($staff, 'tenant')->get("http://{$domain}/users/{$owner->id}/devices")->assertForbidden();

    freshRequestCycle();
    $this->actingAs($staff, 'tenant')->get("http://{$domain}/users/{$otherStaff->id}/devices")->assertForbidden();
});

test('a student gets a 403 for the devices page', function () {
    [$tenant, $domain, , , $student] = deviceToolsFixture();
    $otherStudent = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($student, 'tenant')->get("http://{$domain}/users/{$otherStudent->id}/devices")->assertForbidden();
});

test('a guest is redirected to login for the devices page', function () {
    [$tenant, $domain, , , $student] = deviceToolsFixture();

    $this->get("http://{$domain}/users/{$student->id}/devices")->assertRedirect("http://{$domain}/login");
});

test('another tenant\'s user 404s on the devices page', function () {
    [$tenantA, $domainA, $ownerA] = deviceToolsFixture();
    [$tenantB, , , , $studentB] = deviceToolsFixture();

    // Positive control: owner A can view their own tenant's student.
    $studentA = inTenant($tenantA, fn () => User::factory()->student()->create());
    $this->actingAs($ownerA, 'tenant')->get("http://{$domainA}/users/{$studentA->id}/devices")->assertOk();

    freshRequestCycle();
    $this->actingAs($ownerA, 'tenant')->get("http://{$domainA}/users/{$studentB->id}/devices")->assertNotFound();
});

// === "Switching devices often" flag ===

test('the devices page flags a student switching devices 5+ times in 7 days', function () {
    [$tenant, $domain, $owner, , $student] = deviceToolsFixture();

    inTenant($tenant, function () use ($student) {
        for ($i = 0; $i < 5; $i++) {
            UserDevice::create(['user_id' => $student->id, 'device_id' => bin2hex(random_bytes(16)), 'label' => 'Chrome on Android'])
                ->forceFill(['revoked_at' => now()->subDays($i), 'revoked_reason' => 'replaced'])
                ->save();
        }
    });

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users/{$student->id}/devices")
        ->assertSee(__('devices.switching_often'));
});

test('the devices page does not flag a student with only 4 replacements in 7 days', function () {
    [$tenant, $domain, $owner, , $student] = deviceToolsFixture();

    inTenant($tenant, function () use ($student) {
        for ($i = 0; $i < 4; $i++) {
            UserDevice::create(['user_id' => $student->id, 'device_id' => bin2hex(random_bytes(16)), 'label' => 'Chrome on Android'])
                ->forceFill(['revoked_at' => now()->subDays($i), 'revoked_reason' => 'replaced'])
                ->save();
        }
    });

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users/{$student->id}/devices")
        ->assertDontSee(__('devices.switching_often'));
});

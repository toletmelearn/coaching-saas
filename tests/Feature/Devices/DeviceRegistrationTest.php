<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Str;

function deviceLoginFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    return [$tenant, $domain, $student];
}

function loginAs(string $domain, User $user, ?string $deviceCookie = null)
{
    $request = $deviceCookie !== null ? test()->withCookie('device_id', $deviceCookie) : test();

    return $request->post("http://{$domain}/login", [
        'identifier' => $user->email,
        'password' => 'password',
    ]);
}

test('a fresh login mints a device cookie with the right attributes', function () {
    [$tenant, $domain, $student] = deviceLoginFixture();

    $response = loginAs($domain, $student);
    $response->assertRedirect();

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'device_id');

    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(300)->getTimestamp());
    expect($cookie->getExpiresTime())->toBeLessThan(now()->addDays(400)->getTimestamp());

    inTenant($tenant, fn () => expect(UserDevice::where('user_id', $student->id)->count())->toBe(1));
});

test('the device cookie is Secure when SESSION_SECURE_COOKIE is enabled', function () {
    config(['session.secure' => true]);
    [, $domain, $student] = deviceLoginFixture();

    $response = loginAs($domain, $student);
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'device_id');

    expect($cookie)->not->toBeNull();
    expect($cookie->isSecure())->toBeTrue();
});

test('the device cookie is not Secure when SESSION_SECURE_COOKIE is disabled', function () {
    config(['session.secure' => false]);
    [, $domain, $student] = deviceLoginFixture();

    $response = loginAs($domain, $student);
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'device_id');

    expect($cookie)->not->toBeNull();
    expect($cookie->isSecure())->toBeFalse();
});

test('owner login creates no device row and is never limited', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    loginAs($domain, $owner)->assertRedirect();
    loginAs($domain, $owner, Str::random(32))->assertRedirect();

    inTenant($tenant, fn () => expect(UserDevice::count())->toBe(0));
});

test('staff login creates no device row and is never limited', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    loginAs($domain, $staff)->assertRedirect();

    inTenant($tenant, fn () => expect(UserDevice::count())->toBe(0));
});

test('logging in again on the same device reuses its row and evicts nothing', function () {
    [$tenant, $domain, $student] = deviceLoginFixture();
    $deviceId = bin2hex(random_bytes(16));

    loginAs($domain, $student, $deviceId)->assertRedirect();
    freshRequestCycle();
    loginAs($domain, $student, $deviceId)->assertRedirect();

    inTenant($tenant, function () use ($student) {
        expect(UserDevice::where('user_id', $student->id)->count())->toBe(1);
        expect(UserDevice::where('user_id', $student->id)->whereNotNull('revoked_at')->count())->toBe(0);
    });
});

test('limit 1: a second device login revokes the first with reason replaced', function () {
    [$tenant, $domain, $student] = deviceLoginFixture();
    inTenant($tenant, fn () => $tenant->forceFill(['max_devices_per_student' => 1])->save());

    $deviceA = bin2hex(random_bytes(16));
    $deviceB = bin2hex(random_bytes(16));

    loginAs($domain, $student, $deviceA)->assertRedirect();
    freshRequestCycle();
    loginAs($domain, $student, $deviceB)->assertRedirect();

    inTenant($tenant, function () use ($student, $deviceA, $deviceB) {
        $first = UserDevice::where('user_id', $student->id)->where('device_id', $deviceA)->firstOrFail();
        $second = UserDevice::where('user_id', $student->id)->where('device_id', $deviceB)->firstOrFail();

        expect($first->revoked_at)->not->toBeNull();
        expect($first->revoked_reason->value)->toBe('replaced');
        expect($second->revoked_at)->toBeNull();
    });
});

test('limit 2: a third device login revokes the oldest, never the current one', function () {
    [$tenant, $domain, $student] = deviceLoginFixture();
    inTenant($tenant, fn () => $tenant->forceFill(['max_devices_per_student' => 2])->save());

    $deviceA = bin2hex(random_bytes(16));
    $deviceB = bin2hex(random_bytes(16));
    $deviceC = bin2hex(random_bytes(16));

    loginAs($domain, $student, $deviceA)->assertRedirect();
    freshRequestCycle();
    loginAs($domain, $student, $deviceB)->assertRedirect();
    freshRequestCycle();
    loginAs($domain, $student, $deviceC)->assertRedirect();

    inTenant($tenant, function () use ($student, $deviceA, $deviceB, $deviceC) {
        $a = UserDevice::where('user_id', $student->id)->where('device_id', $deviceA)->firstOrFail();
        $b = UserDevice::where('user_id', $student->id)->where('device_id', $deviceB)->firstOrFail();
        $c = UserDevice::where('user_id', $student->id)->where('device_id', $deviceC)->firstOrFail();

        expect($a->revoked_at)->not->toBeNull();
        expect($b->revoked_at)->toBeNull();
        expect($c->revoked_at)->toBeNull();
    });
});

test('limit 3: a fourth device login revokes only the oldest', function () {
    [$tenant, $domain, $student] = deviceLoginFixture();
    inTenant($tenant, fn () => $tenant->forceFill(['max_devices_per_student' => 3])->save());

    $devices = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];

    foreach ($devices as $i => $deviceId) {
        loginAs($domain, $student, $deviceId)->assertRedirect();

        if ($i < count($devices) - 1) {
            freshRequestCycle();
        }
    }

    inTenant($tenant, function () use ($student, $devices) {
        $rows = collect($devices)->map(fn ($id) => UserDevice::where('user_id', $student->id)->where('device_id', $id)->firstOrFail());

        expect($rows[0]->revoked_at)->not->toBeNull();
        expect($rows[1]->revoked_at)->toBeNull();
        expect($rows[2]->revoked_at)->toBeNull();
        expect($rows[3]->revoked_at)->toBeNull();
    });
});

test('interleaved registrations never exceed the limit or leave zero active devices', function () {
    [$tenant, $domain, $student] = deviceLoginFixture();
    inTenant($tenant, fn () => $tenant->forceFill(['max_devices_per_student' => 2])->save());

    for ($i = 0; $i < 6; $i++) {
        loginAs($domain, $student, bin2hex(random_bytes(16)))->assertRedirect();
        freshRequestCycle();
    }

    inTenant($tenant, function () use ($student) {
        $activeCount = UserDevice::where('user_id', $student->id)->whereNull('revoked_at')->count();

        expect($activeCount)->toBeGreaterThan(0)->toBeLessThanOrEqual(2);
    });
});

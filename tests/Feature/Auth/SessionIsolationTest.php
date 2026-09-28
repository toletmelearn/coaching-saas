<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

// === Session Regeneration ===

test('session id changes on login', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Establish a real, initialized session before login (a GET request boots
    // the session so Session::getId() below reflects an actual pre-login id)
    $this->get("http://{$domain}/login");
    $sessionBefore = Session::getId();

    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'password123',
    ]);

    $sessionAfter = Session::getId();

    expect($sessionAfter)->not->toBe($sessionBefore);
});

test('session is invalidated on logout', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create());

    // Positive control: user is authenticated before logout
    $this->actingAs($user, 'tenant');
    $this->assertAuthenticated('tenant');

    // Logout
    $this->post("http://{$domain}/logout");

    // Session should be invalidated
    $this->assertGuest('tenant');
});

// === Cross-Tenant Session Replay ===

test('session from tenant A cannot access user from tenant B', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';

    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    $userA = inTenant($tenantA, fn () => User::factory()->create([
        'email' => 'user-a@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Login to tenant A
    $this->post("http://{$domainA}/login", [
        'identifier' => 'user-a@example.com',
        'password' => 'password123',
    ]);

    $this->assertAuthenticatedAs($userA, 'tenant');

    // Try to access tenant B with same session
    $this->get("http://{$domainB}/dashboard");

    // Should be guest on tenant B
    $this->assertGuest('tenant');
});

test('session from tenant A resolves to guest on tenant B domain', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';

    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    $userA = inTenant($tenantA, fn () => User::factory()->create());

    // Positive control: forging the tenant guard's actual session key authenticates
    // on tenant A's own domain
    $this->withSession([auth('tenant')->getName() => $userA->id])
        ->get("http://{$domainA}/dashboard")
        ->assertOk();
    $this->assertAuthenticatedAs($userA, 'tenant');

    // Request to tenant B should resolve to guest, even with the same forged key
    $this->get("http://{$domainB}/dashboard");

    $this->assertGuest('tenant');
});

// === Central Domain Session Handling ===

test('tenant user id in central domain session returns guest without exception', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create());

    // Positive control: forging the tenant guard's actual session key authenticates
    // on the tenant's own domain
    $this->withSession([auth('tenant')->getName() => $user->id])
        ->get("http://{$domain}/dashboard")
        ->assertOk();
    $this->assertAuthenticatedAs($user, 'tenant');

    // Request to central domain should return guest, not throw exception
    $this->get('http://coaching.test/');

    $this->assertGuest('tenant');
});

// === Disabled User Session Invalidation ===

test('disabling a logged-in user logs them out on next request', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->active()->create());

    // Login
    $this->actingAs($user, 'tenant');
    $this->assertAuthenticated('tenant');

    // Disable user in database
    inTenant($tenant, fn () => $user->forceFill(['status' => 'disabled'])->save());

    // Next request should log them out
    $this->get("http://{$domain}/dashboard");

    $this->assertGuest('tenant');
});

// === Session Scoping by Hostname ===

test('session cookie is scoped to exact tenant hostname', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Laravel's session domain should be null
    $sessionDomain = config('session.domain');

    expect($sessionDomain)->toBeNull();
});

test('session cookie is not shared across tenant subdomains', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';

    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    $userA = inTenant($tenantA, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Login to tenant A
    $responseA = $this->post("http://{$domainA}/login", [
        'identifier' => 'user@example.com',
        'password' => 'password123',
    ]);

    // Positive control: the login succeeded and authenticated on tenant A
    $responseA->assertRedirect();
    $this->assertAuthenticatedAs($userA, 'tenant');

    // The test client keeps the session between requests; hitting tenant B's
    // domain with that same session must not carry the tenant A authentication over
    $this->get("http://{$domainB}/dashboard");

    // Should be guest on tenant B
    $this->assertGuest('tenant');
});

test('user provider scopes user lookup by resolved tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = inTenant($tenantA, fn () => User::factory()->create());

    // Positive control: user A is findable within tenant A's own context
    $foundInA = inTenant($tenantA, fn () => User::find($userA->id));
    expect($foundInA)->not->toBeNull();
    expect($foundInA->id)->toBe($userA->id);

    // In tenant B context, user A should not be found by ID
    $found = inTenant($tenantB, fn () => User::find($userA->id));

    expect($found)->toBeNull();
});

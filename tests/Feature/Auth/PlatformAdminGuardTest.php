<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// === Platform Admin Guard ===

test('platform admin login succeeds on central domain', function () {
    // First verify the admin login route exists and works
    $admin = PlatformAdmin::factory()->create([
        'email' => 'admin@coaching.test',
        'password' => Hash::make('admin-password'),
    ]);

    $response = $this->post('http://coaching.test/admin/login', [
        'email' => 'admin@coaching.test',
        'password' => 'admin-password',
    ]);

    // Route must exist and redirect on success
    $response->assertRedirect();
    $this->assertAuthenticatedAs($admin, 'platform_admin');
});

test('platform admin cannot log in on tenant domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $admin = PlatformAdmin::factory()->create([
        'email' => 'admin@coaching.test',
        'password' => Hash::make('admin-password'),
    ]);

    // Positive control: these credentials work on the central domain
    $this->post('http://coaching.test/admin/login', [
        'email' => 'admin@coaching.test',
        'password' => 'admin-password',
    ])->assertRedirect();
    $this->assertAuthenticatedAs($admin, 'platform_admin');

    // Return to a guest state before testing the tenant-domain attempt
    auth('platform_admin')->logout();
    $this->assertGuest('platform_admin');

    $response = $this->post("http://{$domain}/admin/login", [
        'email' => 'admin@coaching.test',
        'password' => 'admin-password',
    ]);

    // The route itself is registered only on central domains; a tenant subdomain
    // attempt has no matching route at all.
    $response->assertNotFound();
    $this->assertGuest('platform_admin');
});

test('tenant user cannot log in as platform admin on central domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Positive control: these credentials work as a tenant login
    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'password123',
    ])->assertRedirect();
    $this->assertAuthenticatedAs($user, 'tenant');
    auth('tenant')->logout();

    $response = $this->post('http://coaching.test/admin/login', [
        'email' => 'user@example.com',
        'password' => 'password123',
    ]);

    // Should fail
    $response->assertSessionHasErrors();
    $this->assertGuest('platform_admin');
});

test('platform admin session is not accessible via tenant guard', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $admin = PlatformAdmin::factory()->create([
        'email' => 'admin@coaching.test',
        'password' => Hash::make('admin-password'),
    ]);

    // Login as platform admin (positive control: this login works)
    $this->post('http://coaching.test/admin/login', [
        'email' => 'admin@coaching.test',
        'password' => 'admin-password',
    ]);

    $this->assertAuthenticatedAs($admin, 'platform_admin');

    // Try to access tenant routes with platform_admin session
    $response = $this->get("http://{$domain}/dashboard");

    // Should be guest on tenant domain
    $this->assertGuest('tenant');
});

test('tenant user session is not accessible via platform admin guard', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Login as tenant user (positive control: this login works)
    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'password123',
    ]);

    $this->assertAuthenticatedAs($user, 'tenant');

    // Try to access admin routes with tenant session
    $response = $this->get('http://coaching.test/admin/dashboard');

    // Should be guest as platform_admin
    $this->assertGuest('platform_admin');
});

// === Platform Admin Routes ===

test('platform admin can access admin routes', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/dashboard');

    $response->assertOk();
});

test('tenant user cannot access admin routes', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: a real platform admin can access the route
    $admin = PlatformAdmin::factory()->create();
    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/dashboard')
        ->assertOk();

    freshRequestCycle();

    $user = inTenant($tenant, fn () => User::factory()->create());

    $response = $this->actingAs($user, 'tenant')
        ->get('http://coaching.test/admin/dashboard');

    $response->assertRedirect();
    $this->assertGuest('platform_admin');
});

test('unauthenticated user cannot access admin routes', function () {
    // Positive control: a real platform admin can access the route
    $admin = PlatformAdmin::factory()->create();
    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/dashboard')
        ->assertOk();

    freshRequestCycle();

    $response = $this->get('http://coaching.test/admin/dashboard');

    $response->assertRedirect('http://coaching.test/admin/login');
});

// === Tenant User Guard ===

test('tenant user can access tenant routes', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create());

    $response = $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/dashboard");

    $response->assertOk();
});

test('platform admin cannot access tenant routes', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: a real tenant user can access the route
    $tenantUser = inTenant($tenant, fn () => User::factory()->create());
    $this->actingAs($tenantUser, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertOk();

    freshRequestCycle();

    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get("http://{$domain}/dashboard");

    $response->assertRedirect();
    $this->assertGuest('tenant');
});

test('unauthenticated user cannot access tenant routes', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: a real tenant user can access the route
    $tenantUser = inTenant($tenant, fn () => User::factory()->create());
    $this->actingAs($tenantUser, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertOk();

    freshRequestCycle();

    $response = $this->get("http://{$domain}/dashboard");

    $response->assertRedirect("http://{$domain}/login");
});

// === Platform Admin Login Rate Limiting ===

test('platform admin login is rate limited after 5 failed attempts', function () {
    $admin = PlatformAdmin::factory()->create([
        'email' => 'admin@coaching.test',
        'password' => Hash::make('admin-password'),
    ]);

    // Positive control: a single failed attempt is not throttled
    $this->post('http://coaching.test/admin/login', [
        'email' => 'admin@coaching.test',
        'password' => 'wrong',
    ])->assertSessionHasErrors();

    // 4 more failed attempts (5 total)
    for ($i = 0; $i < 4; $i++) {
        $this->post('http://coaching.test/admin/login', [
            'email' => 'admin@coaching.test',
            'password' => 'wrong',
        ]);
    }

    // 6th attempt should be throttled
    $response = $this->post('http://coaching.test/admin/login', [
        'email' => 'admin@coaching.test',
        'password' => 'wrong',
    ]);

    $response->assertStatus(429);
});

test('platform admin rate limit is keyed by email and IP, a different IP is not throttled', function () {
    PlatformAdmin::factory()->create([
        'email' => 'admin@coaching.test',
        'password' => Hash::make('admin-password'),
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->post('http://coaching.test/admin/login', [
                'email' => 'admin@coaching.test',
                'password' => 'wrong',
            ]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post('http://coaching.test/admin/login', [
            'email' => 'admin@coaching.test',
            'password' => 'wrong',
        ])->assertStatus(429);

    // Same email from a different IP must NOT be throttled
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->post('http://coaching.test/admin/login', [
            'email' => 'admin@coaching.test',
            'password' => 'wrong',
        ]);

    $response->assertSessionHasErrors();
    $response->assertStatus(302);
});

// === Guard Separation ===

test('logging out as platform admin does not affect tenant session', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));
    $admin = PlatformAdmin::factory()->create();

    // Login as tenant user
    $this->post("http://{$domain}/login", [
        'identifier' => $user->email,
        'password' => 'password123',
    ]);
    $this->assertAuthenticatedAs($user, 'tenant');

    // Login as platform admin (separate guard)
    $this->actingAs($admin, 'platform_admin');
    $this->assertAuthenticatedAs($admin, 'platform_admin');

    // Log out the platform admin guard only
    auth('platform_admin')->logout();

    $this->assertGuest('platform_admin');
    $this->assertAuthenticatedAs($user, 'tenant');
});

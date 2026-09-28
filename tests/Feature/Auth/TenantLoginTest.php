<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// === Login with Email ===

test('login with email succeeds on correct tenant domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->active()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('password123'),
    ]));

    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'login@example.com',
        'password' => 'password123',
        'remember' => false,
    ]);

    $response->assertRedirect();
    $loggedInUser = inTenant($tenant, fn () => User::where('email', 'login@example.com')->first());
    $this->assertAuthenticatedAs($loggedInUser, 'tenant');
});

test('login with phone succeeds on correct tenant domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-b.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->active()->create([
        'phone' => '9876543210',
        'password' => Hash::make('password123'),
    ]));

    $response = $this->post("http://{$domain}/login", [
        'identifier' => '9876543210',
        'password' => 'password123',
        'remember' => false,
    ]);

    $response->assertRedirect();
    $this->assertAuthenticated('tenant');
});

test('wrong password returns error with generic message', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('correct-password'),
    ]));

    // Positive control: the correct password logs in successfully
    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'correct-password',
    ])->assertRedirect();
    $this->assertAuthenticated('tenant');
    auth('tenant')->logout();

    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest('tenant');
});

test('unknown identifier returns error with generic message', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: a known identifier with the correct password logs in successfully
    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'known@example.com',
        'password' => Hash::make('correct-password'),
    ]));

    $this->post("http://{$domain}/login", [
        'identifier' => 'known@example.com',
        'password' => 'correct-password',
    ])->assertRedirect();
    $this->assertAuthenticated('tenant');
    auth('tenant')->logout();

    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'unknown@example.com',
        'password' => 'any-password',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest('tenant');
});

test('wrong password and unknown identifier return same error message', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'exists@example.com',
        'password' => Hash::make('password'),
    ]));

    // Positive control: the correct password for this identifier logs in successfully
    $this->post("http://{$domain}/login", [
        'identifier' => 'exists@example.com',
        'password' => 'password',
    ])->assertRedirect();
    $this->assertAuthenticated('tenant');
    auth('tenant')->logout();

    // Read each response's flashed session errors immediately after its own request —
    // the next request's flash would otherwise overwrite the previous one's. Calling
    // assertSessionHasErrors() first (rather than TestResponse::session(), which is
    // protected) is what actually resolves the flashed ViewErrorBag correctly here.
    $wrongPassword = $this->post("http://{$domain}/login", [
        'identifier' => 'exists@example.com',
        'password' => 'wrong',
    ]);
    $wrongPassword->assertSessionHasErrors();
    $wrongMsg = app('session.store')->get('errors')->first();

    $unknownEmail = $this->post("http://{$domain}/login", [
        'identifier' => 'nonexistent@example.com',
        'password' => 'any',
    ]);
    $unknownEmail->assertSessionHasErrors();
    $unknownMsg = app('session.store')->get('errors')->first();

    expect($wrongMsg)->not->toBeNull();
    expect($wrongMsg)->toBe($unknownMsg);
});

test('tenant A user cannot log in on tenant B domain with same identifier and password', function () {
    $tenantA = Tenant::factory()->create();
    $domainA = 'tenant-a.coaching.test';
    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);

    $tenantB = Tenant::factory()->create();
    $domainB = 'tenant-b.coaching.test';
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    inTenant($tenantA, fn () => User::factory()->active()->create([
        'email' => 'shared@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Positive control: works on tenant A's own domain
    $this->post("http://{$domainA}/login", [
        'identifier' => 'shared@example.com',
        'password' => 'password123',
    ])->assertRedirect();
    $this->assertAuthenticated('tenant');
    auth('tenant')->logout();

    // Same identifier + password, tenant B's domain: identifier doesn't exist under tenant B
    $response = $this->post("http://{$domainB}/login", [
        'identifier' => 'shared@example.com',
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest('tenant');
});

test('disabled user cannot log in', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: an active user with the same password can log in
    inTenant($tenant, fn () => User::factory()->active()->create([
        'email' => 'active@example.com',
        'password' => Hash::make('password123'),
    ]));

    $this->post("http://{$domain}/login", [
        'identifier' => 'active@example.com',
        'password' => 'password123',
    ])->assertRedirect();
    $this->assertAuthenticated('tenant');
    auth('tenant')->logout();

    inTenant($tenant, fn () => User::factory()->disabled()->create([
        'email' => 'disabled@example.com',
        'password' => Hash::make('password123'),
    ]));

    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'disabled@example.com',
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest('tenant');
});

test('must_change_password redirects to password change page on login', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: a normal login (must_change_password false) redirects to the dashboard, not change-password
    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'normal@example.com',
        'password' => Hash::make('password123'),
    ]));

    $this->post("http://{$domain}/login", [
        'identifier' => 'normal@example.com',
        'password' => 'password123',
    ])->assertRedirect("http://{$domain}/dashboard");
    auth('tenant')->logout();

    inTenant($tenant, fn () => User::factory()->mustChangePassword()->create([
        'email' => 'must-change@example.com',
        'password' => Hash::make('password123'),
    ]));

    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'must-change@example.com',
        'password' => 'password123',
    ]);

    $response->assertRedirect("http://{$domain}/auth/change-password");
});

test('accessing protected page with must_change_password redirects to change-password', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: a normal user (must_change_password false) can reach the dashboard
    $normalUser = inTenant($tenant, fn () => User::factory()->create());
    $this->actingAs($normalUser, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertOk();

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertRedirect("http://{$domain}/auth/change-password");
});

test('after changing password, must_change_password is false', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    $this->actingAs($user, 'tenant')
        ->post("http://{$domain}/auth/change-password", [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

    inTenant($tenant, fn () => $user->refresh());

    expect($user->must_change_password)->toBeFalse();
});

test('last_login_at is updated on successful login', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->active()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
        'last_login_at' => null,
    ]));

    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'password123',
    ]);

    inTenant($tenant, fn () => $user->refresh());

    expect($user->last_login_at)->not->toBeNull();
});

test('remember-me sets persistent cookie via tenant scope', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'remember@example.com',
        'password' => Hash::make('password123'),
    ]));

    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'remember@example.com',
        'password' => 'password123',
        'remember' => true,
    ]);

    // Should have remember_token set
    inTenant($tenant, fn () => $user->refresh());
    expect($user->remember_token)->not->toBeNull();
});

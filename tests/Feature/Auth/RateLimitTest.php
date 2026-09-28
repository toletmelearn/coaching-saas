<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// === Rate Limiting: Per Tenant + Identifier ===

test('login is rate limited after 5 failed attempts on same tenant and identifier', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Positive control: a single failed attempt is a normal (non-throttled) failure
    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors()->assertStatus(302);

    // 4 more failed attempts (5 total)
    for ($i = 0; $i < 4; $i++) {
        $this->post("http://{$domain}/login", [
            'identifier' => 'user@example.com',
            'password' => 'wrong-password',
        ]);
    }

    // 6th attempt should be throttled
    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429); // Too Many Requests
});

test('rate limit is per tenant and identifier combination', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user1@example.com',
        'password' => Hash::make('password123'),
    ]));

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user2@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Positive control: a single failed attempt for user1 is not throttled
    $this->post("http://{$domain}/login", [
        'identifier' => 'user1@example.com',
        'password' => 'wrong',
    ])->assertSessionHasErrors()->assertStatus(302);

    // 4 more failed attempts for user1 (5 total)
    for ($i = 0; $i < 4; $i++) {
        $this->post("http://{$domain}/login", [
            'identifier' => 'user1@example.com',
            'password' => 'wrong',
        ]);
    }

    // user1 should be limited
    $response1 = $this->post("http://{$domain}/login", [
        'identifier' => 'user1@example.com',
        'password' => 'wrong',
    ]);
    $response1->assertStatus(429);

    // user2 should still work (different identifier)
    $response2 = $this->post("http://{$domain}/login", [
        'identifier' => 'user2@example.com',
        'password' => 'wrong',
    ]);
    $response2->assertSessionHasErrors();
    $response2->assertStatus(302); // Not throttled
});

test('rate limit is per tenant, different tenant resets count', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';

    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    inTenant($tenantA, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    inTenant($tenantB, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Positive control: a single failed attempt on tenant A is not throttled
    $this->post("http://{$domainA}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong',
    ])->assertSessionHasErrors()->assertStatus(302);

    // 4 more failed attempts on tenant A (5 total)
    for ($i = 0; $i < 4; $i++) {
        $this->post("http://{$domainA}/login", [
            'identifier' => 'user@example.com',
            'password' => 'wrong',
        ]);
    }

    // tenant A should be limited
    $responseA = $this->post("http://{$domainA}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong',
    ]);
    $responseA->assertStatus(429);

    // Same identifier on tenant B should still work (different tenant)
    $responseB = $this->post("http://{$domainB}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong',
    ]);
    $responseB->assertSessionHasErrors();
    $responseB->assertStatus(302); // Not throttled
});

test('rate limit is keyed by IP address as well', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Positive control: a single failed attempt from this IP is not throttled
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post("http://{$domain}/login", [
            'identifier' => 'user@example.com',
            'password' => 'wrong',
        ])->assertSessionHasErrors()->assertStatus(302);

    // 4 more failed attempts from IP 10.0.0.1 (5 total)
    for ($i = 0; $i < 4; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->post("http://{$domain}/login", [
                'identifier' => 'user@example.com',
                'password' => 'wrong',
            ]);
    }

    // Should be throttled for this IP
    $response1 = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post("http://{$domain}/login", [
            'identifier' => 'user@example.com',
            'password' => 'wrong',
        ]);
    $response1->assertStatus(429);

    // Same identifier from a different IP must NOT be throttled
    $response2 = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->post("http://{$domain}/login", [
            'identifier' => 'user@example.com',
            'password' => 'wrong',
        ]);
    $response2->assertSessionHasErrors();
    $response2->assertStatus(302); // Not throttled
});

test('rate limit resets after time window', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Positive control: a single failed attempt is not throttled
    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong',
    ])->assertSessionHasErrors()->assertStatus(302);

    // 4 more failed attempts (5 total)
    for ($i = 0; $i < 4; $i++) {
        $this->post("http://{$domain}/login", [
            'identifier' => 'user@example.com',
            'password' => 'wrong',
        ]);
    }

    // Confirm throttled before clearing
    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong',
    ])->assertStatus(429);

    // Simulate the throttle window passing (RateLimiter::clear() requires a
    // specific key and errors without one; travel forward in time instead)
    $this->travel(61)->seconds();

    // Next attempt should work
    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong',
    ]);

    $response->assertSessionHasErrors();
    $response->assertStatus(302); // Not throttled
});

test('successful login does not trigger rate limit', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Login successfully
    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'password123',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticated('tenant');
});

test('rate limit message does not reveal identifier existence', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
    ]));

    // Positive control: a single failed attempt is not throttled
    $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong',
    ])->assertSessionHasErrors()->assertStatus(302);

    // 4 more failed attempts (5 total)
    for ($i = 0; $i < 4; $i++) {
        $this->post("http://{$domain}/login", [
            'identifier' => 'user@example.com',
            'password' => 'wrong',
        ]);
    }

    // 6th attempt should show throttle message without revealing the identifier
    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'user@example.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(429);
    $response->assertDontSee('user@example.com');
});

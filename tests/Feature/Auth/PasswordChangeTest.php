<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// === First-Login Password Change ===

test('account created by owner has must_change_password = true', function () {
    $tenant = Tenant::factory()->create();

    // Owner creates a new student account
    $student = inTenant($tenant, fn () => User::factory()->student()->mustChangePassword()->create([
        'name' => 'New Student',
        'email' => 'newstudent@example.com',
        'phone' => '9876543210',
        'password' => Hash::make('temp-password'),
    ]));

    expect($student->must_change_password)->toBeTrue();
});

test('login with must_change_password true redirects to change-password page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->mustChangePassword()->create([
        'email' => 'mustchange@example.com',
        'password' => Hash::make('temp-password'),
    ]));

    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'mustchange@example.com',
        'password' => 'temp-password',
    ]);

    $response->assertRedirect("http://{$domain}/auth/change-password");
});

test('accessing dashboard with must_change_password true redirects', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: a normal user (must_change_password false) can reach the dashboard
    $normalUser = inTenant($tenant, fn () => User::factory()->create());
    $this->actingAs($normalUser, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertOk();

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    $response = $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/dashboard");

    $response->assertRedirect("http://{$domain}/auth/change-password");
});

test('change-password page shows only after login with must_change_password', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    $response = $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/auth/change-password");

    $response->assertOk();
});

test('submitting change-password form sets must_change_password to false', function () {
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

test('after changing password, accessing dashboard succeeds', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    $this->actingAs($user, 'tenant')
        ->post("http://{$domain}/auth/change-password", [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

    $response = $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/dashboard");

    $response->assertOk();
});

test('password reset by owner sets must_change_password to true', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $student = inTenant($tenant, fn () => User::factory()->student()->mustChangePassword(false)->create());

    // Owner resets student's password
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/reset-password", [
            'password' => 'reset-password',
        ]);

    inTenant($tenant, fn () => $student->refresh());

    expect($student->must_change_password)->toBeTrue();
});

test('change-password form validates password length', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    $response = $this->actingAs($user, 'tenant')
        ->post("http://{$domain}/auth/change-password", [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

    $response->assertSessionHasErrors('password');
});

test('change-password requires password confirmation', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    $response = $this->actingAs($user, 'tenant')
        ->post("http://{$domain}/auth/change-password", [
            'password' => 'newpassword123',
            'password_confirmation' => 'different-password',
        ]);

    $response->assertSessionHasErrors('password');
});

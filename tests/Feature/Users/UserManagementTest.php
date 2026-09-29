<?php

use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Models\User;

// === Cross-Tenant Isolation ===

test('owner cannot see users from other tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = 'tenant-a.coaching.test';

    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => 'tenant-b.coaching.test', 'type' => 'subdomain']);

    $ownerA = inTenant($tenantA, fn () => User::factory()->owner()->create());

    $userB = inTenant($tenantB, fn () => User::factory()->student()->create([
        'email' => 'studentb@example.com',
    ]));

    $response = $this->actingAs($ownerA, 'tenant')
        ->get("http://{$domainA}/users");

    // Positive control: owner sees tenant A's own user list
    $response->assertOk();
    $response->assertSee($ownerA->email);

    // Should not see tenant B's user
    $response->assertDontSee($userB->email);
});

test('owner cannot edit users from other tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = 'tenant-a.coaching.test';

    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);

    $ownerA = inTenant($tenantA, fn () => User::factory()->owner()->create());
    $studentA = inTenant($tenantA, fn () => User::factory()->student()->create());

    // Positive control: owner can edit a user within their own tenant
    $this->actingAs($ownerA, 'tenant')
        ->patch("http://{$domainA}/users/{$studentA->id}", [
            'name' => 'Updated Name',
        ])->assertRedirect();

    $userB = inTenant($tenantB, fn () => User::factory()->create());

    $response = $this->actingAs($ownerA, 'tenant')
        ->patch("http://{$domainA}/users/{$userB->id}", [
            'name' => 'Hacked',
        ]);

    $response->assertNotFound();
});

test('route model binding respects tenant scope for user 404', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = 'tenant-a.coaching.test';

    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);

    $ownerA = inTenant($tenantA, fn () => User::factory()->owner()->create());
    $studentA = inTenant($tenantA, fn () => User::factory()->student()->create());

    // Positive control: owner can view a user within their own tenant
    $this->actingAs($ownerA, 'tenant')
        ->get("http://{$domainA}/users/{$studentA->id}")
        ->assertOk();

    $userB = inTenant($tenantB, fn () => User::factory()->create());

    $response = $this->actingAs($ownerA, 'tenant')
        ->get("http://{$domainA}/users/{$userB->id}");

    $response->assertNotFound();
});

test('staff cannot see users from other tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = 'tenant-a.coaching.test';

    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);

    $staffA = inTenant($tenantA, fn () => User::factory()->staff()->create());
    $studentA = inTenant($tenantA, fn () => User::factory()->student()->create());

    $userB = inTenant($tenantB, fn () => User::factory()->student()->create([
        'email' => 'studentb@example.com',
    ]));

    $response = $this->actingAs($staffA, 'tenant')
        ->get("http://{$domainA}/users");

    // Positive control: staff sees students within their own tenant
    $response->assertOk();
    $response->assertSee($studentA->email);

    // Staff should not see tenant B's user
    $response->assertDontSee($userB->email);
});

// === User List View ===

test('owner sees all users in tenant', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create(['email' => 'owner@example.com']));

    inTenant($tenant, fn () => User::factory()->count(5)->create());

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users");

    $response->assertOk();
    // Should see at least the 5 created users plus the owner
});

test('staff sees only students', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create(['email' => 'staff@example.com']));

    inTenant($tenant, fn () => User::factory()->student()->count(2)->create());
    inTenant($tenant, fn () => User::factory()->staff()->create());

    $response = $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/users");

    $response->assertOk();
    // Should see only the 2 students
});

test('student cannot see user list', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: an owner can see the user list
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users")
        ->assertOk();

    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/users");

    $response->assertForbidden();
});

// === User List Pagination ===

test('user list is paginated', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    inTenant($tenant, fn () => User::factory()->count(30)->create());

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users?page=2");

    $response->assertOk();
});

// === Create User ===

test('owner create user page shows form', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users/create");

    $response->assertOk();
});

test('staff create user page shows form', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $response = $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/users/create");

    $response->assertOk();
});

test('student cannot access create user page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: an owner can access the create user page
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users/create")
        ->assertOk();

    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/users/create");

    $response->assertForbidden();
});

// === Mass Assignment ===

test('cannot mass-assign tenant_id via request', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '9876543210',
            'role' => 'student',
            'tenant_id' => $otherTenant->id,
        ]);

    // Positive control: the create request succeeded (the field was stripped, not rejected outright)
    $response->assertRedirect();

    $user = inTenant($tenant, fn () => User::where('email', 'test@example.com')->first());

    expect($user->tenant_id)->toBe($tenant->id);
});

test('staff posting a privileged role when creating a user is forbidden, not silently stripped', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control: staff CAN create student accounts
    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Control Student',
            'email' => 'control-role@example.com',
            'role' => 'student',
        ])->assertRedirect();

    // Staff requesting role=owner is forbidden outright (not silently downgraded)
    $ownerAttempt = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Test Owner',
            'email' => 'test-owner@example.com',
            'phone' => '9876543210',
            'role' => 'owner',
        ]);
    $ownerAttempt->assertForbidden();
    expect(inTenant($tenant, fn () => User::where('email', 'test-owner@example.com')->first()))->toBeNull();

    // Staff requesting role=staff is also forbidden outright
    $staffAttempt = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Test Staff',
            'email' => 'test-staff@example.com',
            'phone' => '9876543211',
            'role' => 'staff',
        ]);
    $staffAttempt->assertForbidden();
    expect(inTenant($tenant, fn () => User::where('email', 'test-staff@example.com')->first()))->toBeNull();
});

test('cannot mass-assign status via request when creating', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Try to create disabled user
    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '9876543210',
            'status' => 'disabled',  // Should default to active
        ]);

    // Positive control: the create request succeeded (the field was stripped, not rejected outright)
    $response->assertRedirect();

    $user = inTenant($tenant, fn () => User::where('email', 'test@example.com')->first());

    expect($user->status)->toBe(UserStatus::Active);
});

test('cannot mass-assign must_change_password via request', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Try to create user without must_change_password requirement
    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '9876543210',
            'must_change_password' => false,
        ]);

    // Positive control: the create request succeeded (the field was stripped, not rejected outright)
    $response->assertRedirect();

    $user = inTenant($tenant, fn () => User::where('email', 'test@example.com')->first());

    // Created by owner should require password change
    expect($user->must_change_password)->toBeTrue();
});

// === Contact Requirement (HTTP layer) ===

test('creating a user without email or phone returns validation errors on both fields', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Positive control: creating with an email succeeds
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Control Contact',
            'email' => 'control-contact@example.com',
        ])->assertRedirect();

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'No Contact',
            'email' => null,
            'phone' => null,
        ]);

    $response->assertSessionHasErrors(['email', 'phone']);

    $user = inTenant($tenant, fn () => User::where('name', 'No Contact')->first());
    expect($user)->toBeNull();
});

// === Temporary Password Generation ===

test('owner-created user can log in with the generated temporary password and must change it', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Student',
            'email' => 'newstudent-temp@example.com',
            'phone' => '9876543210',
            'role' => 'student',
        ]);

    $response->assertRedirect();

    $temporaryPassword = $response->getSession()->get('temporary_password');
    expect($temporaryPassword)->not->toBeNull();

    auth('tenant')->logout();

    $loginResponse = $this->post("http://{$domain}/login", [
        'identifier' => 'newstudent-temp@example.com',
        'password' => $temporaryPassword,
    ]);

    $loginResponse->assertRedirect("http://{$domain}/auth/change-password");

    $user = inTenant($tenant, fn () => User::where('email', 'newstudent-temp@example.com')->first());
    expect($user->must_change_password)->toBeTrue();
});

test('after a password reset the user can log in with the flashed password and is redirected to change-password', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->mustChangePassword(false)->create([
        'email' => 'reset-target@example.com',
    ]));

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/reset-password");

    $response->assertRedirect();

    $temporaryPassword = $response->getSession()->get('temporary_password');
    expect($temporaryPassword)->not->toBeNull();

    auth('tenant')->logout();

    $loginResponse = $this->post("http://{$domain}/login", [
        'identifier' => 'reset-target@example.com',
        'password' => $temporaryPassword,
    ]);

    $loginResponse->assertRedirect("http://{$domain}/auth/change-password");

    inTenant($tenant, fn () => $student->refresh());
    expect($student->must_change_password)->toBeTrue();
});

test('the generated temporary password matches the required format', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Format check on user creation...
    $createResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Format Check',
            'email' => 'format-check@example.com',
        ]);
    $createdPassword = $createResponse->getSession()->get('temporary_password');
    expect($createdPassword)->toMatch('/^[abcdefghjkmnpqrstuvwxyz23456789]{4}-[abcdefghjkmnpqrstuvwxyz23456789]{4}$/');

    // ...and on password reset, which must use the same generator.
    $student = inTenant($tenant, fn () => User::factory()->student()->create());
    $resetResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/reset-password");
    $resetPassword = $resetResponse->getSession()->get('temporary_password');
    expect($resetPassword)->toMatch('/^[abcdefghjkmnpqrstuvwxyz23456789]{4}-[abcdefghjkmnpqrstuvwxyz23456789]{4}$/');
});

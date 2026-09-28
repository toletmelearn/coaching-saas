<?php

use App\Models\Tenant;
use App\Models\User;

// === Role-Based Permissions ===

test('owner can create staff accounts', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Staff',
            'email' => 'staff@example.com',
            'phone' => '9876543210',
            'role' => 'staff',
        ]);

    $response->assertRedirect();

    $staff = inTenant($tenant, fn () => User::where('email', 'staff@example.com')->first());
    expect($staff->role)->toBe('staff');
});

test('owner can create student accounts', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Student',
            'email' => 'student@example.com',
            'phone' => '9876543210',
            'role' => 'student',
        ]);

    $response->assertRedirect();

    $student = inTenant($tenant, fn () => User::where('email', 'student@example.com')->first());
    expect($student->role)->toBe('student');
});

test('staff can create student accounts', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $response = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Student',
            'email' => 'student@example.com',
            'phone' => '9876543210',
            'role' => 'student',
        ]);

    $response->assertRedirect();

    $student = inTenant($tenant, fn () => User::where('email', 'student@example.com')->first());
    expect($student->role)->toBe('student');
});

test('staff cannot create staff accounts', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control: staff CAN create student accounts
    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Student',
            'email' => 'newstudent-control@example.com',
            'role' => 'student',
        ])->assertRedirect();

    $response = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Staff',
            'email' => 'newstaff@example.com',
            'role' => 'staff',
        ]);

    $response->assertForbidden();
    expect(inTenant($tenant, fn () => User::where('email', 'newstaff@example.com')->first()))->toBeNull();
});

test('staff cannot create owner accounts', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control: staff CAN create student accounts
    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Student',
            'email' => 'newstudent-control2@example.com',
            'role' => 'student',
        ])->assertRedirect();

    $response = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Owner',
            'email' => 'newowner@example.com',
            'role' => 'owner',
        ]);

    $response->assertForbidden();
    expect(inTenant($tenant, fn () => User::where('email', 'newowner@example.com')->first()))->toBeNull();
});

test('student cannot create accounts', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: an owner can create accounts
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'Control User',
            'email' => 'control@example.com',
        ])->assertRedirect();

    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $response = $this->actingAs($student, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

    $response->assertForbidden();
});

// === Owner Deletion/Demotion Protection ===

test('last owner of tenant cannot be disabled', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner1 = inTenant($tenant, fn () => User::factory()->owner()->create());
    $owner2 = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Positive control: disabling a non-last owner succeeds
    $this->actingAs($owner1, 'tenant')
        ->post("http://{$domain}/users/{$owner2->id}/disable")
        ->assertRedirect();

    // owner1 is now the last remaining owner; disabling them must be forbidden
    $response = $this->actingAs($owner1, 'tenant')
        ->post("http://{$domain}/users/{$owner1->id}/disable");

    $response->assertForbidden();
});

test('last owner of tenant cannot be demoted', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner1 = inTenant($tenant, fn () => User::factory()->owner()->create());
    $owner2 = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Positive control: demoting a non-last owner succeeds
    $this->actingAs($owner1, 'tenant')
        ->patch("http://{$domain}/users/{$owner2->id}", ['role' => 'staff'])
        ->assertRedirect();

    // owner1 is now the last remaining owner; demoting them must be forbidden
    $response = $this->actingAs($owner1, 'tenant')
        ->patch("http://{$domain}/users/{$owner1->id}", [
            'role' => 'staff',
        ]);

    $response->assertForbidden();
});

test('non-last owner can be disabled', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner1 = inTenant($tenant, fn () => User::factory()->owner()->create());
    $owner2 = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner1, 'tenant')
        ->post("http://{$domain}/users/{$owner2->id}/disable");

    $response->assertRedirect();

    inTenant($tenant, fn () => $owner2->refresh());
    expect($owner2->status)->toBe('disabled');
});

test('owner can re-enable a disabled user', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $staff = inTenant($tenant, fn () => User::factory()->staff()->disabled()->create());

    // Positive control: the disabled user really is disabled beforehand
    expect($staff->status)->toBe('disabled');

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$staff->id}/enable");

    $response->assertRedirect();

    inTenant($tenant, fn () => $staff->refresh());
    expect($staff->status)->toBe('active');
});

test('non-last owner can be demoted', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner1 = inTenant($tenant, fn () => User::factory()->owner()->create());
    $owner2 = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner1, 'tenant')
        ->patch("http://{$domain}/users/{$owner2->id}", [
            'role' => 'staff',
        ]);

    $response->assertRedirect();

    inTenant($tenant, fn () => $owner2->refresh());
    expect($owner2->role)->toBe('staff');
});

// === Staff Management Permissions ===

test('owner can reset staff password', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$staff->id}/reset-password", [
            'password' => 'newpassword123',
        ]);

    $response->assertRedirect();
});

test('staff can reset student password', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $response = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/reset-password", [
            'password' => 'newpassword123',
        ]);

    $response->assertRedirect();
});

test('staff cannot reset staff password', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff1 = inTenant($tenant, fn () => User::factory()->staff()->create());
    $staff2 = inTenant($tenant, fn () => User::factory()->staff()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control: staff CAN reset a student's password
    $this->actingAs($staff1, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/reset-password", [
            'password' => 'newpassword123',
        ])->assertRedirect();

    $response = $this->actingAs($staff1, 'tenant')
        ->post("http://{$domain}/users/{$staff2->id}/reset-password", [
            'password' => 'newpassword123',
        ]);

    $response->assertForbidden();
});

// === Student Self-Management ===

test('student can only edit their own profile', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $student1 = inTenant($tenant, fn () => User::factory()->student()->create());
    $student2 = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control: a student can edit their own profile
    $this->actingAs($student1, 'tenant')
        ->patch("http://{$domain}/users/{$student1->id}", ['name' => 'My Own Name'])
        ->assertRedirect();

    $response = $this->actingAs($student1, 'tenant')
        ->patch("http://{$domain}/users/{$student2->id}", [
            'name' => 'Hacked',
        ]);

    $response->assertForbidden();
});

test('student can edit their own name', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $student = inTenant($tenant, fn () => User::factory()->student()->create(['name' => 'Old Name']));

    $response = $this->actingAs($student, 'tenant')
        ->patch("http://{$domain}/users/{$student->id}", [
            'name' => 'New Name',
        ]);

    $response->assertRedirect();

    inTenant($tenant, fn () => $student->refresh());
    expect($student->name)->toBe('New Name');
});

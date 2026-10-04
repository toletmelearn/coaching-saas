<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ConsentFixtures;

test('creating a user shows the temporary password prominently on the People page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $storeResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users", [
            'name' => 'New Student',
            'email' => 'ui-new-student@example.com',
            'role' => 'student',
        ] + ConsentFixtures::guardianFields() + [
            'consents' => ConsentFixtures::PURPOSES,
            'consent_method' => ConsentFixtures::IMPORT_METHOD,
        ]);
    $storeResponse->assertRedirect();

    $temporaryPassword = $storeResponse->getSession()->get('temporary_password');
    expect($temporaryPassword)->not->toBeNull();

    // The flash is available on the very next request — the redirect target itself.
    $response = $this->get("http://{$domain}/users");

    $response->assertOk();
    $response->assertSee($temporaryPassword);
    $response->assertSee('New Student');
    $response->assertSee('ui-new-student@example.com');
    $response->assertSee(__('users.temporary_password_share_warning'));
});

test('resetting a password shows the temporary password prominently on the People page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $student = User::factory()->student()->create(['name' => 'Reset Target', 'email' => 'reset-ui@example.com']);

        return [$owner, $student];
    });

    $resetResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/reset-password");
    $resetResponse->assertRedirect();

    $temporaryPassword = $resetResponse->getSession()->get('temporary_password');
    expect($temporaryPassword)->not->toBeNull();

    $response = $this->get("http://{$domain}/users");

    $response->assertOk();
    $response->assertSee($temporaryPassword);
    $response->assertSee('Reset Target');
    $response->assertSee('reset-ui@example.com');
    $response->assertSee(__('users.temporary_password_share_warning'));
});

test('People rows only show the actions the viewer is allowed to take', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $staff, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create(['name' => 'Owner Row']);
        $staff = User::factory()->staff()->create(['name' => 'Staff Row']);
        $student = User::factory()->student()->create(['name' => 'Student Row']);

        return [$owner, $staff, $student];
    });

    // Positive control: owner sees reset/disable actions for every row, including staff.
    $ownerView = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users");
    $ownerView->assertOk();
    $ownerView->assertSeeInOrder([$staff->name, __('users.actions.reset_password')]);
    $ownerView->assertSeeInOrder([$staff->name, __('users.actions.disable')]);

    freshRequestCycle();

    // Staff can manage the student row (reset/disable visible) but not the owner or
    // another staff row (no reset/disable button for those rows).
    $staffView = $this->actingAs($staff, 'tenant')->get("http://{$domain}/users");
    $staffView->assertOk();
    $staffView->assertSeeInOrder([$student->name, __('users.actions.reset_password')]);
    $staffView->assertSeeInOrder([$student->name, __('users.actions.disable')]);
});

test("resetting a password clears the reset user's login rate-limit keys (both limiters)", function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $student = User::factory()->student()->create([
            'email' => 'locked-out@example.com',
            'password' => Hash::make('password123'),
        ]);

        return [$owner, $student];
    });

    // Trip the per-IP limiter (5/min) for this identifier.
    for ($i = 0; $i < 5; $i++) {
        $this->post("http://{$domain}/login", [
            'identifier' => 'locked-out@example.com',
            'password' => 'wrong',
        ]);
    }
    $this->post("http://{$domain}/login", [
        'identifier' => 'locked-out@example.com',
        'password' => 'wrong',
    ])->assertStatus(429);

    $resetResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/{$student->id}/reset-password");
    $resetResponse->assertRedirect();

    $temporaryPassword = $resetResponse->getSession()->get('temporary_password');

    freshRequestCycle();

    // Positive control: login now succeeds with the new password (proves the account
    // itself isn't the problem) — but the real point of this test is that it isn't 429.
    $response = $this->post("http://{$domain}/login", [
        'identifier' => 'locked-out@example.com',
        'password' => $temporaryPassword,
    ]);

    $response->assertStatus(302);
    $response->assertRedirect("http://{$domain}/auth/change-password");
});

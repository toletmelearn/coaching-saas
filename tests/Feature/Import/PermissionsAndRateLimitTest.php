<?php

use App\Models\Tenant;
use App\Models\User;

function permFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    return [$tenant, $domain];
}

test('owner can access the import upload screen (positive control)', function () {
    [$tenant, $domain] = permFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import")->assertOk();
});

test('staff can access the import upload screen (positive control)', function () {
    [$tenant, $domain] = permFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($staff, 'tenant')->get("http://{$domain}/users/import")->assertOk();
});

test('a student is forbidden from the import screen', function () {
    [$tenant, $domain] = permFixture();
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($student, 'tenant')->get("http://{$domain}/users/import")->assertForbidden();
});

test('a guest is redirected to login for the import screen', function () {
    [, $domain] = permFixture();

    $this->get("http://{$domain}/users/import")->assertRedirect("http://{$domain}/login");
});

test('staff-created import rows are always role student, even if a role is supplied', function () {
    [$tenant, $domain] = permFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";

    $preview = $this->actingAs($staff, 'tenant')->post("http://{$domain}/users/import", [
        'file' => csvUploadFile($csv),
        'role' => 'owner',
    ]);
    $token = $preview->viewData('token');
    $this->actingAs($staff, 'tenant')->post("http://{$domain}/users/import/confirm", ['token' => $token]);

    inTenant($tenant, function () {
        $student = User::where('phone', '9876543210')->first();
        expect($student->role->value)->toBe('student');
    });
});

test('the 11th import within an hour is rate limited', function () {
    [$tenant, $domain] = permFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($owner, 'tenant')
            ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
            ->assertStatus(200);
    }

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertStatus(429);
});

test('the 10th import within an hour still succeeds (positive control)', function () {
    [$tenant, $domain] = permFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";

    for ($i = 0; $i < 9; $i++) {
        $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);
    }

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertStatus(200);
});

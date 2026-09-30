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
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control in the same test: an owner CAN reach the screen. Without this,
    // "a student is forbidden" would also be satisfied by the route not existing at all
    // (a 404 for everyone, student included, looks identical to "forbidden" unless we
    // also prove the screen is reachable by someone).
    $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import")->assertOk();

    freshRequestCycle();

    $this->actingAs($student, 'tenant')->get("http://{$domain}/users/import")->assertForbidden();
});

test('a guest is redirected to login for the import screen, while an owner can reach it', function () {
    [$tenant, $domain] = permFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // Positive control in the same test, and load-bearing: GET /users/import as a guest
    // currently 404s against Laravel's own routing (no route matches until Step 2 adds
    // one) in a way indistinguishable from "redirected" only by accident — the existing
    // GET /users/{user} route pattern matches the literal string "import" as {user},
    // and auth:tenant middleware on that route redirects an unauthenticated guest before
    // route-model-binding ever runs, making the assertion below pass whether or not the
    // real import screen exists. Requiring the owner to actually reach a 200 first closes
    // that gap: it fails now (owner also 404s, from the same accidental route match,
    // since implicit binding fails to resolve a User for "import") and will only pass
    // once the dedicated /users/import route exists.
    $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import")->assertOk();

    freshRequestCycle();

    $this->get("http://{$domain}/users/import")->assertRedirect("http://{$domain}/login");
});

test('staff-created import rows are always role student, even if a role column/parameter is supplied', function () {
    [$tenant, $domain] = permFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());
    $csv = "name,phone,email,role\nAsha Rao,9876543210,,owner\n";

    $preview = $this->actingAs($staff, 'tenant')->post("http://{$domain}/users/import", [
        'file' => csvUploadFile($csv),
        'role' => 'owner',
    ]);
    $preview->assertOk();
    $token = $preview->viewData('token');
    $this->actingAs($staff, 'tenant')->post("http://{$domain}/users/import/confirm", [
        'token' => $token,
        'role' => 'owner',
    ]);

    inTenant($tenant, function () {
        $student = User::where('phone', '9876543210')->first();
        expect($student)->not->toBeNull();
        expect($student->role->value)->toBe('student');
    });
});

test('owner-created import rows are also always role student, even if a role column/parameter is supplied', function () {
    [$tenant, $domain] = permFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $csv = "name,phone,email,role\nRahul Jain,9123456780,,staff\n";

    $preview = $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import", [
        'file' => csvUploadFile($csv),
        'role' => 'staff',
    ]);
    $preview->assertOk();
    $token = $preview->viewData('token');
    $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", [
        'token' => $token,
        'role' => 'staff',
    ]);

    inTenant($tenant, function () {
        $student = User::where('phone', '9123456780')->first();
        expect($student)->not->toBeNull();
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

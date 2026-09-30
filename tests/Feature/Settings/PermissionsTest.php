<?php

use App\Models\Tenant;
use App\Models\User;

test('owner can open and save settings', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/settings")
        ->assertOk();

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/settings", [
            'name' => 'Updated Institute Name',
            'contact_phone' => '',
            'contact_email' => '',
            'theme_color' => '#4f46e5',
            'academic_year_end' => '',
        ])
        ->assertRedirect();

    inTenant($tenant, fn () => $tenant->refresh());
    expect($tenant->name)->toBe('Updated Institute Name');
});

test('staff sees the friendly 403 page for settings', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control: staff CAN reach an ordinary tenant page (proves the request
    // reaches a real, working route, not a routing-level 404, before hitting the 403).
    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertOk();

    freshRequestCycle();

    $response = $this->actingAs($staff, 'tenant')->get("http://{$domain}/manage/settings");
    $response->assertForbidden();
    $response->assertSee(__('errors.403.heading'));
    $response->assertSee(__('errors.403.message'));

    freshRequestCycle();

    $this->actingAs($staff, 'tenant')
        ->patch("http://{$domain}/manage/settings", ['name' => 'Hacked'])
        ->assertForbidden();
});

test('student sees the friendly 403 page for settings', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control: student CAN reach the public course catalogue.
    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses")
        ->assertOk();

    freshRequestCycle();

    $response = $this->actingAs($student, 'tenant')->get("http://{$domain}/manage/settings");
    $response->assertForbidden();
    $response->assertSee(__('errors.403.heading'));
});

test('guest is redirected to login', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: the tenant's domain resolves and serves a public page fine for a
    // guest, proving the redirect below is auth-driven, not the domain failing to resolve.
    $this->get("http://{$domain}/courses")->assertOk();

    $this->get("http://{$domain}/manage/settings")
        ->assertRedirect("http://{$domain}/login");
});

test('an owner of tenant A cannot alter tenant B settings; no tenant id is accepted from the request', function () {
    $tenantA = Tenant::factory()->create(['name' => 'Institute A']);
    $tenantB = Tenant::factory()->create(['name' => 'Institute B']);
    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';
    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    $ownerA = inTenant($tenantA, fn () => User::factory()->owner()->create());

    // Attempt to smuggle tenant B's id through every plausible field name; the tenant
    // acted on must always be resolved from TenantContext (the request's own hostname),
    // never from anything in the request body.
    $this->actingAs($ownerA, 'tenant')
        ->patch("http://{$domainA}/manage/settings", [
            'name' => 'Renamed via A',
            'tenant_id' => $tenantB->id,
            'id' => $tenantB->id,
        ])
        ->assertRedirect();

    $tenantA->refresh();
    $tenantB->refresh();

    expect($tenantA->name)->toBe('Renamed via A');
    expect($tenantB->name)->toBe('Institute B');
});

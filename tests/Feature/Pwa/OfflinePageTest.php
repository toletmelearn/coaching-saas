<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;

test('the offline page shows the institute name and generic text only, even for a logged-in user', function () {
    $tenant = Tenant::factory()->create(['name' => 'Bright Future Academy']);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $student = inTenant($tenant, fn () => User::factory()->student()->create(['name' => 'Priya Sharma']));

    $response = $this->actingAs($student, 'tenant')->get("http://{$domain}/offline");

    $response->assertOk();
    $response->assertSee('Bright Future Academy');
    $response->assertDontSee('Priya Sharma');
});

test('offline page works on a tenant domain and 404s on a central domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: the route works on the tenant's own domain.
    $this->get("http://{$domain}/offline")->assertOk();

    $this->get('http://coaching.test/offline')->assertNotFound();
});

test('offline page is 200 for an active institute and 503 for a suspended one', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: active tenant serves the offline page fine.
    $this->get("http://{$domain}/offline")->assertOk();

    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    $this->get("http://{$domain}/offline")->assertStatus(503);
});

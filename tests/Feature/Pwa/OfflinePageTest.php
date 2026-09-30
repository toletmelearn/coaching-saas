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

test('offline page is 404 on a central domain', function () {
    $this->get('http://coaching.test/offline')->assertNotFound();
});

test('offline page is 503 for a suspended institute', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $this->get("http://{$domain}/offline")->assertStatus(503);
});

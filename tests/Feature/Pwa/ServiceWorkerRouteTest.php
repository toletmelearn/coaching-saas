<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;

test('sw.js is served with the correct headers', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/sw.js");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('javascript');
    $response->assertHeader('Cache-Control', 'no-cache');
    $response->assertHeader('Service-Worker-Allowed', '/');
});

test('sw.js works on a tenant domain and 404s on a central domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: the route works on the tenant's own domain.
    $this->get("http://{$domain}/sw.js")->assertOk();

    $this->get('http://coaching.test/sw.js')->assertNotFound();
});

test('sw.js is 200 for an active institute and 503 for a suspended one', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: active tenant serves sw.js fine.
    $this->get("http://{$domain}/sw.js")->assertOk();

    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    $this->get("http://{$domain}/sw.js")->assertStatus(503);
});

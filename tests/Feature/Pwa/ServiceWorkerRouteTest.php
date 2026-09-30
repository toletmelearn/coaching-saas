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

test('sw.js is 404 on a central domain', function () {
    $this->get('http://coaching.test/sw.js')->assertNotFound();
});

test('sw.js is 503 for a suspended institute', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $this->get("http://{$domain}/sw.js")->assertStatus(503);
});

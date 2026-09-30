<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;

test('each supported icon size is served with the right headers', function (string $size) {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/pwa/icons/{$size}.png");

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'public, max-age=86400');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Type'))->toContain('image/png');
})->with(['180', '192', '512', '512-maskable']);

test('an unsupported icon size 404s', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $this->get("http://{$domain}/pwa/icons/64.png")->assertNotFound();
});

test('icons are 404 on a central domain', function () {
    $this->get('http://coaching.test/pwa/icons/512.png')->assertNotFound();
});

test('icons are 503 for a suspended institute', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $this->get("http://{$domain}/pwa/icons/512.png")->assertStatus(503);
});

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

test('a supported icon size serves fine; an unsupported size 404s on the same tenant', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: a supported size works on this tenant (proves the 404 below is
    // the size being rejected, not the route being unregistered).
    $this->get("http://{$domain}/pwa/icons/512.png")->assertOk();

    $this->get("http://{$domain}/pwa/icons/64.png")->assertNotFound();
});

test('icons work on a tenant domain and 404 on a central domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: the route works on the tenant's own domain.
    $this->get("http://{$domain}/pwa/icons/512.png")->assertOk();

    $this->get('http://coaching.test/pwa/icons/512.png')->assertNotFound();
});

test('icons are 200 for an active institute and 503 for a suspended one', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: active tenant serves the icon fine.
    $this->get("http://{$domain}/pwa/icons/512.png")->assertOk();

    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    $this->get("http://{$domain}/pwa/icons/512.png")->assertStatus(503);
});

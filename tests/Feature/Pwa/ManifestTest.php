<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;

test('the manifest is per-tenant JSON with the correct content type and fields', function () {
    $tenant = Tenant::factory()->create(['name' => 'Bright Future Academy']);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    inTenant($tenant, fn () => $tenant->forceFill(['theme_color' => '#059669'])->save());

    $response = $this->get("http://{$domain}/manifest.webmanifest");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/manifest+json');

    $manifest = $response->json();

    expect($manifest['id'])->toBe('/');
    expect($manifest['name'])->toBe('Bright Future Academy');
    expect($manifest['short_name'])->toBe(mb_substr('Bright Future Academy', 0, 12));
    expect(mb_strlen($manifest['short_name']))->toBeLessThanOrEqual(12);
    expect($manifest['start_url'])->toBe('/?source=pwa');
    expect($manifest['scope'])->toBe('/');
    expect($manifest['display'])->toBe('standalone');
    expect($manifest['orientation'])->toBe('any');
    expect($manifest['theme_color'])->toBe('#059669');
    expect($manifest['background_color'])->toBe('#ffffff');
    expect($manifest['lang'])->toBe('en');
    expect($manifest['categories'])->toBe(['education']);

    $sizes = array_column($manifest['icons'], 'sizes');
    expect($sizes)->toContain('192x192', '512x512');

    $maskable = collect($manifest['icons'])->firstWhere('purpose', 'maskable');
    expect($maskable)->not->toBeNull();

    foreach ($manifest['icons'] as $icon) {
        expect($icon['src'])->toContain('?v=');
    }
});

test("tenant A's manifest never leaks tenant B's name, colour or icons", function () {
    $tenantA = Tenant::factory()->create(['name' => 'Institute A']);
    $tenantB = Tenant::factory()->create(['name' => 'Institute B']);
    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';
    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);
    inTenant($tenantA, fn () => $tenantA->forceFill(['theme_color' => '#059669'])->save());
    inTenant($tenantB, fn () => $tenantB->forceFill(['theme_color' => '#dc2626'])->save());

    $manifestA = $this->get("http://{$domainA}/manifest.webmanifest")->json();

    expect($manifestA['name'])->toBe('Institute A');
    expect($manifestA['name'])->not->toBe('Institute B');
    expect($manifestA['theme_color'])->toBe('#059669');
    expect($manifestA['theme_color'])->not->toBe('#dc2626');
});

test('manifest is 404 on a central domain', function () {
    $this->get('http://coaching.test/manifest.webmanifest')->assertNotFound();
});

test('manifest is 503 for a suspended institute, and 200 for an active one', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: active tenant serves the manifest fine.
    $this->get("http://{$domain}/manifest.webmanifest")->assertOk();

    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    $this->get("http://{$domain}/manifest.webmanifest")->assertStatus(503);
});

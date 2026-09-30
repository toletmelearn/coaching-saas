<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;

function extractCacheVersion(string $swContent): string
{
    preg_match("/CACHE_NAME = 'coaching-saas-' \+ '([^']+)';/", $swContent, $match);

    return $match[1] ?? '';
}

test('sw.js is served with the correct headers', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/sw.js");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('javascript');
    // Symfony's ResponseHeaderBag appends ", private" to a bare "no-cache" directive
    // unless the response is explicitly marked public — assert the directive is
    // present rather than the exact string.
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    $response->assertHeader('Service-Worker-Allowed', '/');
});

test('sw.js has the __CACHE_VERSION__ placeholder replaced with a real value', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/sw.js");

    $response->assertOk();
    $response->assertDontSee('__CACHE_VERSION__', false);
    expect($response->getContent())->toContain("const CACHE_NAME = 'coaching-saas-");
});

test('the version embedded in sw.js changes when the built asset manifest changes', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $manifestPath = public_path('build/manifest.json');
    $originalManifest = file_exists($manifestPath) ? file_get_contents($manifestPath) : null;

    try {
        file_put_contents($manifestPath, '{"marker":"phase-6-1-test-a"}');
        $versionA = extractCacheVersion($this->get("http://{$domain}/sw.js")->getContent());

        file_put_contents($manifestPath, '{"marker":"phase-6-1-test-b"}');
        $versionB = extractCacheVersion($this->get("http://{$domain}/sw.js")->getContent());

        expect($versionA)->not->toBe($versionB);
    } finally {
        if ($originalManifest === null) {
            @unlink($manifestPath);
        } else {
            file_put_contents($manifestPath, $originalManifest);
        }
    }
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

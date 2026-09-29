<?php

use App\Models\Tenant;

test('a forwarded Host header from Cloudflare is never trusted for tenant resolution', function () {
    $tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
    $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);

    $domainA = 'tenant-a.coaching.test';
    $domainB = 'tenant-b.coaching.test';
    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    $cloudflareIp = '104.16.0.5'; // within Cloudflare's published ranges

    // Positive control: a direct (non-forwarded) request to tenant B's own host resolves
    // tenant B — proves the assertion below is actually distinguishing tenants, not just
    // always landing on whichever one happens to render first.
    $this->withServerVariables(['REMOTE_ADDR' => $cloudflareIp])
        ->get("http://{$domainB}/login")
        ->assertSee('Tenant B');

    // Positive control: the same request to tenant A's host, with no forwarded-host header
    // at all, resolves tenant A.
    $this->withServerVariables(['REMOTE_ADDR' => $cloudflareIp])
        ->get("http://{$domainA}/login")
        ->assertSee('Tenant A');

    // The actual case: a request from Cloudflare to tenant A's own host, but carrying an
    // X-Forwarded-Host claiming to be tenant B. Tenant resolution is host-based
    // (ResolveTenant uses $request->getHost()), so if X-Forwarded-Host were honoured this
    // would resolve tenant B instead — it must still resolve tenant A.
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => $cloudflareIp,
        'HTTP_X_FORWARDED_HOST' => $domainB,
    ])->get("http://{$domainA}/login");

    $response->assertSee('Tenant A');
    $response->assertDontSee('Tenant B');
});

<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * These use the real per-IP login rate limiter (5 failed attempts/minute, keyed by
 * tenant+identifier+ip — see App\Support\LoginRateLimiter) as the observable proof of
 * what $request->ip() actually resolved to, since that's the real thing in this app that
 * depends on getting the visitor's real IP right behind Cloudflare.
 */
function failLogin(string $domain, string $identifier, string $remoteAddr, ?string $forwardedFor = null)
{
    $server = ['REMOTE_ADDR' => $remoteAddr];

    if ($forwardedFor !== null) {
        $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
    }

    return test()->withServerVariables($server)->post("http://{$domain}/login", [
        'identifier' => $identifier,
        'password' => 'wrong',
    ]);
}

test('a request from a Cloudflare IP with X-Forwarded-For is rate-limited by the visitor IP, not the edge IP', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'cf-visitor@example.com',
        'password' => Hash::make('password123'),
    ]));

    $cloudflareEdgeIp1 = '104.16.0.5'; // within 104.16.0.0/13
    $cloudflareEdgeIp2 = '172.64.0.9'; // within 172.64.0.0/13 — a different edge node
    $visitorIp = '203.0.113.7';

    // Positive control: a single failed attempt through Cloudflare is not yet throttled.
    failLogin($domain, 'cf-visitor@example.com', $cloudflareEdgeIp1, $visitorIp)
        ->assertSessionHasErrors()->assertStatus(302);

    for ($i = 0; $i < 4; $i++) {
        failLogin($domain, 'cf-visitor@example.com', $cloudflareEdgeIp1, $visitorIp);
    }

    // 6th attempt, routed through a DIFFERENT Cloudflare edge IP but the same real visitor
    // IP, must still be throttled — proving the limiter keyed on the forwarded visitor IP,
    // not on the (irrelevant, edge-to-edge-varying) REMOTE_ADDR.
    $response = failLogin($domain, 'cf-visitor@example.com', $cloudflareEdgeIp2, $visitorIp);
    $response->assertStatus(429);
});

test('a forged X-Forwarded-For from a non-Cloudflare IP is ignored', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'forged-header@example.com',
        'password' => Hash::make('password123'),
    ]));

    $nonCloudflareIp = '8.8.8.8';
    $forgedVictimIp = '203.0.113.99';

    // Trip the limiter for the forged victim IP via a real Cloudflare-facing request.
    for ($i = 0; $i < 5; $i++) {
        failLogin($domain, 'forged-header@example.com', '104.16.0.5', $forgedVictimIp);
    }
    failLogin($domain, 'forged-header@example.com', '104.16.0.5', $forgedVictimIp)
        ->assertStatus(429);

    // Positive control: attacker's own real (non-Cloudflare) IP, no forged header, is a
    // fresh bucket and gets a normal (non-throttled) response.
    $legitResponse = failLogin($domain, 'forged-header@example.com', '198.51.100.1');
    $legitResponse->assertSessionHasErrors()->assertStatus(302);

    // The attack: connecting directly (not via Cloudflare) but claiming to be the
    // already-blocked victim IP via a forged header. Since REMOTE_ADDR isn't a Cloudflare
    // IP, the header must be ignored — this request is judged on $nonCloudflareIp itself,
    // a separate, fresh bucket, so it must NOT be 429 (that would mean the forged header
    // was honoured, letting an attacker either impersonate or block a real visitor's IP).
    $forgedResponse = failLogin($domain, 'forged-header@example.com', $nonCloudflareIp, $forgedVictimIp);
    $forgedResponse->assertSessionHasErrors()->assertStatus(302);
});

test('two students behind Cloudflare with different real IPs do not share a rate-limit bucket', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, fn () => User::factory()->create([
        'email' => 'shared-identifier@example.com',
        'password' => Hash::make('password123'),
    ]));

    $edgeIp = '162.158.1.1';
    $studentAIp = '203.0.113.10';
    $studentBIp = '203.0.113.20';

    for ($i = 0; $i < 5; $i++) {
        failLogin($domain, 'shared-identifier@example.com', $edgeIp, $studentAIp);
    }
    failLogin($domain, 'shared-identifier@example.com', $edgeIp, $studentAIp)
        ->assertStatus(429);

    // Student B, same identifier attempted, same Cloudflare edge, but their own real IP —
    // must not be caught by student A's per-IP block.
    $studentBResponse = failLogin($domain, 'shared-identifier@example.com', $edgeIp, $studentBIp);
    $studentBResponse->assertSessionHasErrors()->assertStatus(302);
});

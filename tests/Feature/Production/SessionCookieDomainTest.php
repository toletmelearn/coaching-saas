<?php

use App\Models\Tenant;

test('the session cookie has no Domain attribute, so it is a host-only cookie per subdomain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    $response->assertOk();

    $sessionCookieName = config('session.cookie');
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === $sessionCookieName);

    expect($cookie)->not->toBeNull();
    expect($cookie->getDomain())->toBeNull();

    // Positive control: the config this all rests on is actually null, not just
    // coincidentally unset for this one cookie instance.
    expect(config('session.domain'))->toBeNull();
});

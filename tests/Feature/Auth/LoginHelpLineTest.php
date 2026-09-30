<?php

use App\Models\Tenant;

test('the login page always shows the generic help line', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $this->get("http://{$domain}/login")->assertSee(__('auth.help.generic'));
});

test('call/WhatsApp links appear only when a contact phone is configured, and never otherwise', function () {
    $withPhone = Tenant::factory()->create(['contact_phone' => '9876543210']);
    $domainWithPhone = 'tenant-a.coaching.test';
    $withPhone->domains()->create(['domain' => $domainWithPhone, 'type' => 'subdomain']);

    $withoutPhone = Tenant::factory()->create(['contact_phone' => null]);
    $domainWithoutPhone = 'tenant-b.coaching.test';
    $withoutPhone->domains()->create(['domain' => $domainWithoutPhone, 'type' => 'subdomain']);

    // Positive control first: this must actually succeed, or a missing feature could
    // never be distinguished from the negative case below trivially holding either way.
    $withPhoneResponse = $this->get("http://{$domainWithPhone}/login");
    $withPhoneResponse->assertSee('tel:9876543210', false);
    $withPhoneResponse->assertSee('https://wa.me/919876543210', false);

    $withoutPhoneResponse = $this->get("http://{$domainWithoutPhone}/login");
    $withoutPhoneResponse->assertDontSee('wa.me', false);
    $withoutPhoneResponse->assertDontSee('tel:', false);
});

test('the wa.me help link carries no query string beyond the phone number', function () {
    $tenant = Tenant::factory()->create(['contact_phone' => '9876543210']);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    preg_match('#https://wa\.me/([0-9]+)([^"\']*)#', $response->getContent(), $matches);

    // Positive control: the link must actually be present — otherwise an absent regex
    // match trivially satisfies "no extra query string" without the feature existing.
    expect($matches[1] ?? null)->toBe('919876543210');
    expect($matches[2] ?? '')->toBe('');
});

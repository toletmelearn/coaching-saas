<?php

use App\Models\Tenant;

test('the login page always shows the generic help line', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $this->get("http://{$domain}/login")->assertSee(__('auth.help.generic'));
});

test('call/WhatsApp links appear only when the institute has a contact phone configured', function () {
    $tenant = Tenant::factory()->create(['contact_phone' => '9876543210']);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    $response->assertSee('tel:9876543210', false);
    $response->assertSee('https://wa.me/919876543210', false);
});

test('no phone/WhatsApp links appear when contact phone is not configured (positive control)', function () {
    $tenant = Tenant::factory()->create(['contact_phone' => null]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    $response->assertDontSee('wa.me', false);
});

test('the wa.me help link carries no query string beyond the phone number', function () {
    $tenant = Tenant::factory()->create(['contact_phone' => '9876543210']);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    preg_match('#https://wa\.me/[0-9]+([^"\']*)#', $response->getContent(), $matches);
    expect($matches[1] ?? '')->toBe('');
});

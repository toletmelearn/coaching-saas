<?php

use App\Models\Consent;
use App\Models\Tenant;

test('privacy page is accessible on the central domain without authentication', function () {
    $response = $this->get('http://coaching.test/privacy');

    $response->assertOk();
    $response->assertSee(Consent::NOTICE_VERSION);
    $response->assertSee('Privacy Notice');
    $response->assertSee('Digital Personal Data Protection Act');
});

test('terms page is accessible on the central domain without authentication', function () {
    $response = $this->get('http://coaching.test/terms');

    $response->assertOk();
    $response->assertSee('Terms of Service');
    $response->assertSee('Governing law');
});

test('privacy page is accessible on a tenant subdomain without authentication', function () {
    $tenant = Tenant::factory()->create(['name' => 'Bright Academy']);
    $domain = 'bright.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/privacy");

    $response->assertOk();
    $response->assertSee(Consent::NOTICE_VERSION);
    $response->assertSee('Bright Academy');
});

test('terms page is accessible on a tenant subdomain without authentication', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'terms-test.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/terms");

    $response->assertOk();
    $response->assertSee('Terms of Service');
});

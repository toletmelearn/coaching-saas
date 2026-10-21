<?php

use App\Models\Consent;
use App\Models\Tenant;
use App\Models\User;

test('config/privacy.php data_categories cover every consent purpose in lang/en/consents.php', function () {
    $consentPurposes = array_keys(trans('consents.purposes'));
    sort($consentPurposes);

    $configPurposes = collect(config('privacy.data_categories'))
        ->flatMap(fn ($cat) => $cat['purposes'])
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($configPurposes)->toBe($consentPurposes);
});

test('the notice file for the current Consent::NOTICE_VERSION exists on disk', function () {
    $path = resource_path('legal/notices/'.Consent::NOTICE_VERSION.'.md');
    expect(file_exists($path))->toBeTrue(
        'Missing versioned notice file: '.$path.' — create it or update Consent::NOTICE_VERSION'
    );
});

test('the Hindi notice file for the current Consent::NOTICE_VERSION exists on disk', function () {
    $path = resource_path('legal/notices/'.Consent::NOTICE_VERSION.'.hi.md');
    expect(file_exists($path))->toBeTrue(
        'Missing Hindi notice file: '.$path.' — create it or update Consent::NOTICE_VERSION'
    );
});

test('privacy page returns 200 on the central domain without authentication', function () {
    $response = $this->get('http://coaching.test/privacy');

    $response->assertOk();
    $response->assertSee(Consent::NOTICE_VERSION);
    $response->assertSee('Privacy notice');
    $response->assertSee('Digital Personal Data Protection Act');
});

test('platform terms page returns 200 on the central domain without authentication', function () {
    $response = $this->get('http://coaching.test/terms');

    $response->assertOk();
    $response->assertSee('Platform Terms of Service');
});

test('privacy page on a tenant subdomain shows the tenant name and notice version', function () {
    $tenant = Tenant::factory()->create(['name' => 'Bright Academy']);
    $domain = 'bright.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/privacy");

    $response->assertOk();
    $response->assertSee(Consent::NOTICE_VERSION);
    $response->assertSee('Bright Academy');
});

test('student terms page returns 200 on a tenant subdomain without authentication', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'terms-test.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/terms");

    $response->assertOk();
    $response->assertSee('Student Terms of Use');
});

test('platform terms page is NOT served on a tenant subdomain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'pf-check.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/terms");

    $response->assertOk();
    $response->assertDontSee('Platform Terms of Service');
    $response->assertSee('Student Terms of Use');
});

test('student terms page is NOT served on the central domain', function () {
    $response = $this->get('http://coaching.test/terms');

    $response->assertOk();
    $response->assertDontSee('Student Terms of Use');
    $response->assertSee('Platform Terms of Service');
});

test('archived notice version renders at stable URL on the central domain', function () {
    $response = $this->get('http://coaching.test/privacy/v1.0-2026-10-04');

    $response->assertOk();
    $response->assertSee('v1.0-2026-10-04');
});

test('archived notice version renders at stable URL on a tenant subdomain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'archive-test.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/privacy/v1.0-2026-10-04");

    $response->assertOk();
    $response->assertSee('v1.0-2026-10-04');
});

test('nonexistent archive version returns 404', function () {
    $response = $this->get('http://coaching.test/privacy/v0.0-1900-01-01');

    $response->assertNotFound();
});

test('consents withdraw page returns 200 on a tenant subdomain', function () {
    $tenant = Tenant::factory()->create(['name' => 'River Academy']);
    $domain = 'river.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/consents/withdraw");

    $response->assertOk();
    $response->assertSee('River Academy');
});

test('privacy and terms links appear in the app chrome footer', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'footer-test.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create());

    $response = $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertSee('/privacy');
    $response->assertSee('/terms');
});

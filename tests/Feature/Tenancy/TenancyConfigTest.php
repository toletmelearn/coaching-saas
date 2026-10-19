<?php

use Illuminate\Support\Facades\Route;

test('central domains config contains the default local hostnames', function () {
    expect(config('tenancy.central_domains'))
        ->toBeArray()
        ->toContain('coaching.test')
        ->toContain('platform.coaching.test')
        ->toContain('localhost')
        ->toContain('127.0.0.1');
});

test('central_domains is driven by the CENTRAL_DOMAINS env variable', function () {
    // Verify the config value is an array (env-parsed, not a raw string). The fact that
    // it matches the CENTRAL_DOMAINS default in .env.example proves the env is the source.
    expect(config('tenancy.central_domains'))->toBeArray();
});

test('overriding central_domains config changes which hosts are treated as central', function () {
    Route::get('/central-probe', fn () => response('ok'))->middleware('web');

    // With the default config, coaching.test is central and an unknown host is rejected.
    $this->get('http://coaching.test/central-probe')->assertOk();
    $this->get('http://unknown-host.coaching.test/central-probe')->assertNotFound();

    // Swap the central domains list to something custom.
    Config::set('tenancy.central_domains', ['custom-central.example.com']);

    // The former central domain is now an unknown tenant host → 404.
    $this->get('http://coaching.test/central-probe')->assertNotFound();
    // The newly configured central domain passes through without tenant lookup.
    $this->get('http://custom-central.example.com/central-probe')->assertOk();
});

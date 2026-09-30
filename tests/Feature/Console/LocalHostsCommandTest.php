<?php

use App\Models\Tenant;

test('local:hosts prints a hosts-file line for every tenant domain and the central domains', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'brightfuture.coaching.test', 'type' => 'subdomain']);

    $this->artisan('local:hosts')
        ->expectsOutputToContain('127.0.0.1 brightfuture.coaching.test')
        ->expectsOutputToContain('127.0.0.1 coaching.test')
        ->assertSuccessful();
});

test('local:hosts never prints localhost or 127.0.0.1 as a domain to add', function () {
    $this->artisan('local:hosts')
        ->doesntExpectOutputToContain('127.0.0.1 localhost')
        ->doesntExpectOutputToContain('127.0.0.1 127.0.0.1')
        ->assertSuccessful();
});

test('local:hosts refuses to run outside local/testing', function () {
    // Positive control: it works in the default testing environment.
    $this->artisan('local:hosts')->assertSuccessful();

    app()->instance('env', 'production');
    $this->artisan('local:hosts')->assertFailed();
});

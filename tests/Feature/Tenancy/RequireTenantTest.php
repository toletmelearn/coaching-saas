<?php

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/tenant-required-probe', fn () => response()->json(['ok' => true]))
        ->middleware(['web', 'require.tenant']);
});

test('a request on a known tenant domain passes RequireTenant', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $this->get('http://demo.coaching.test/tenant-required-probe')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

test('a request on a central domain is rejected by RequireTenant', function () {
    $this->get('http://coaching.test/tenant-required-probe')
        ->assertNotFound();
});

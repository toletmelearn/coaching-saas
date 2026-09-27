<?php

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/tenant-context-probe', function (TenantContext $context) {
        return response()->json([
            'has' => $context->has(),
            'tenant_id' => $context->has() ? $context->id() : null,
        ]);
    })->middleware('web');
});

test('a central domain resolves with no tenant bound', function () {
    $response = $this->get('http://coaching.test/tenant-context-probe');

    $response->assertOk()->assertJson(['has' => false, 'tenant_id' => null]);
});

test('a known tenant domain resolves to the correct tenant', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $response = $this->get('http://demo.coaching.test/tenant-context-probe');

    $response->assertOk()->assertJson(['has' => true, 'tenant_id' => $tenant->id]);
});

test('an unknown domain returns 404', function () {
    $response = $this->get('http://nobody-here.coaching.test/tenant-context-probe');

    $response->assertNotFound();
});

test('a subdomain-suffix spoof does not resolve to the real tenant', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $response = $this->get('http://demo.coaching.test.evil.com/tenant-context-probe');

    $response->assertNotFound();
});

test('uppercase host resolves the same as lowercase', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $response = $this->get('http://DEMO.COACHING.TEST/tenant-context-probe');

    $response->assertOk()->assertJson(['has' => true, 'tenant_id' => $tenant->id]);
});

test('trailing-dot host resolves the same as without', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $response = $this->get('http://demo.coaching.test./tenant-context-probe');

    $response->assertOk()->assertJson(['has' => true, 'tenant_id' => $tenant->id]);
});

test('two tenant domains resolve independently', function () {
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();
    TenantDomain::factory()->for($first)->create(['domain' => 'first.coaching.test']);
    TenantDomain::factory()->for($second)->create(['domain' => 'second.coaching.test']);

    $this->get('http://first.coaching.test/tenant-context-probe')
        ->assertJson(['has' => true, 'tenant_id' => $first->id]);

    // Simulate the process boundary between two real HTTP requests: TenantContext
    // is scoped(), so it must not carry the first request's tenant into the second.
    app()->forgetScopedInstances();

    $this->get('http://second.coaching.test/tenant-context-probe')
        ->assertJson(['has' => true, 'tenant_id' => $second->id]);
});

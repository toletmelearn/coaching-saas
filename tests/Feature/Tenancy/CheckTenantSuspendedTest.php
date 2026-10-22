<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/suspended-probe', fn () => response('ok'))
        ->middleware('web');
});

test('active tenant allows requests through', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
    $tenant->domains()->create(['domain' => 'active.coaching.test', 'type' => 'subdomain']);

    $this->get('http://active.coaching.test/suspended-probe')
        ->assertOk();
});

test('central domain allows requests through regardless of middleware', function () {
    $this->get('http://coaching.test/suspended-probe')
        ->assertOk();
});

test('anonymous request to suspended tenant returns 503', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    $tenant->domains()->create(['domain' => 'suspended.coaching.test', 'type' => 'subdomain']);

    $this->get('http://suspended.coaching.test/suspended-probe')
        ->assertStatus(503);
});

test('logged-in user on suspended tenant is logged out and receives 503', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    $tenant->domains()->create(['domain' => 'suspended2.coaching.test', 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create());

    $this->actingAs($user, 'tenant')
        ->get('http://suspended2.coaching.test/suspended-probe')
        ->assertStatus(503);

    $this->assertGuest('tenant');
});

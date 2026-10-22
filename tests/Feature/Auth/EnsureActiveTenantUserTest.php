<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/active-user-probe', fn () => response('ok'))
        ->middleware(['web', 'active.tenant.user']);
});

test('guest passes through active-tenant-user middleware untouched', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'atu.coaching.test', 'type' => 'subdomain']);

    $this->get('http://atu.coaching.test/active-user-probe')
        ->assertOk();
});

test('active user passes through', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'atu2.coaching.test', 'type' => 'subdomain']);
    $user = inTenant($tenant, fn () => User::factory()->active()->create());

    $this->actingAs($user, 'tenant')
        ->get('http://atu2.coaching.test/active-user-probe')
        ->assertOk();
});

test('disabled user is logged out and redirected to login', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'atu3.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $user = inTenant($tenant, fn () => User::factory()->disabled()->create());

    $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/active-user-probe")
        ->assertRedirect("http://{$domain}/login");

    $this->assertGuest('tenant');
});

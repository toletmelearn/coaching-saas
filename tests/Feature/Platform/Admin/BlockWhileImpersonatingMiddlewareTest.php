<?php

use App\Http\Middleware\BlockWhileImpersonating;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::post('/impersonation-guard-probe', fn () => response('ok'))
        ->middleware(['web', BlockWhileImpersonating::class]);
});

test('normal session without impersonation key passes through', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'bwi.coaching.test', 'type' => 'subdomain']);
    $user = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($user, 'tenant')
        ->post('http://bwi.coaching.test/impersonation-guard-probe')
        ->assertOk();
});

test('session carrying impersonation key is rejected with 403', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'bwi2.coaching.test', 'type' => 'subdomain']);

    // EnforceImpersonationExpiry (also in the web group) reads started_at; provide
    // a fresh timestamp so it doesn't expire the session before reaching our middleware.
    $this->withSession(['impersonation' => ['admin_id' => 1, 'started_at' => now()->timestamp]])
        ->post('http://bwi2.coaching.test/impersonation-guard-probe')
        ->assertForbidden();
});

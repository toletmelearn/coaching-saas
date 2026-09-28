<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Models\TenancyTestParent;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'tests/Fixtures/migrations',
        '--realpath' => true,
    ]);

    Route::get('/parents/{parent}', function (TenancyTestParent $parent) {
        return response()->json(['id' => $parent->id, 'name' => $parent->name]);
    })->middleware('web', 'require.tenant');
});

test('route model binding resolves model for correct tenant', function () {
    $tenant = Tenant::factory()->create();
    $domain = $tenant->domains()->create(['domain' => 'test.coaching.test', 'type' => 'subdomain']);

    $model = inTenant($tenant, fn () => TenancyTestParent::factory()->create(['name' => 'Test']));

    $response = $this->get("http://{$domain->domain}/parents/{$model->id}");

    $response->assertOk()
        ->assertJson(['id' => $model->id, 'name' => 'Test']);
});

test('route model binding returns 404 for another tenant record', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $domainB = $tenantB->domains()->create(['domain' => 'testb.coaching.test', 'type' => 'subdomain']);

    $modelA = inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    $response = $this->get("http://{$domainB->domain}/parents/{$modelA->id}");

    $response->assertNotFound();
});

test('route model binding with nonexistent id returns 404', function () {
    $tenant = Tenant::factory()->create();
    $domain = $tenant->domains()->create(['domain' => 'testc.coaching.test', 'type' => 'subdomain']);

    $response = $this->get("http://{$domain->domain}/parents/99999");

    $response->assertNotFound();
});

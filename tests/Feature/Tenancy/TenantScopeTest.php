<?php

use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Models\TenancyTestParent;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'tests/Fixtures/migrations',
        '--realpath' => true,
    ]);
});

test('querying without tenant context throws MissingTenantContextException', function () {
    expect(fn () => TenancyTestParent::all())->toThrow(MissingTenantContextException::class);
});

test('querying with context applies tenant filter', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    inTenant($tenant, fn () => TenancyTestParent::factory()->count(3)->create());
    inTenant($otherTenant, fn () => TenancyTestParent::factory()->count(2)->create());

    $rows = inTenant($tenant, fn () => TenancyTestParent::all());

    expect($rows)->toHaveCount(3);
});

test('scope exception message includes model class', function () {
    try {
        TenancyTestParent::all();
        expect(true)->toBeFalse(); // should have thrown
    } catch (MissingTenantContextException $e) {
        expect($e->getMessage())->toContain('TenancyTestParent');
    }
});

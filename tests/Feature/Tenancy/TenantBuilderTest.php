<?php

use App\Exceptions\InvalidTenantException;
use App\Models\Tenant;
use Tests\Fixtures\Models\TenancyTestParent;

test('query builder update with tenant_id throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    expect(function () use ($tenantB) {
        TenancyTestParent::query()->update(['tenant_id' => $tenantB->id]);
    })->toThrow(InvalidTenantException::class);
});

test('query builder update without tenant_id works and respects tenant scope', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $modelA = inTenant($tenantA, fn () => TenancyTestParent::factory()->create(['name' => 'A']));
    $modelB = inTenant($tenantB, fn () => TenancyTestParent::factory()->create(['name' => 'B']));

    inTenant($tenantA, function () {
        TenancyTestParent::query()->update(['name' => 'Updated']);
    });

    expect(inTenant($tenantA, fn () => TenancyTestParent::find($modelA->id)->name))->toBe('Updated');
    expect(inTenant($tenantB, fn () => TenancyTestParent::find($modelB->id)->name))->toBe('B');
});

test('query builder upsert with tenant_id in values throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    expect(function () use ($tenantA, $tenantB) {
        inTenant($tenantA, function () use ($tenantB) {
            TenancyTestParent::query()->upsert(
                [['id' => 999, 'tenant_id' => $tenantB->id, 'name' => 'X', 'slug' => 'x']],
                'id',
                ['name']
            );
        });
    })->toThrow(InvalidTenantException::class);
});

test('query builder upsert with tenant_id in update clause throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    expect(function () use ($tenantA, $tenantB) {
        inTenant($tenantA, function () use ($tenantB) {
            TenancyTestParent::query()->upsert(
                [['id' => 999, 'name' => 'X', 'slug' => 'x']],
                'id',
                ['tenant_id' => $tenantB->id]
            );
        });
    })->toThrow(InvalidTenantException::class);
});

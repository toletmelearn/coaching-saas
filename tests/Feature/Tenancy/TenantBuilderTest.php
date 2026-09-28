<?php

use App\Exceptions\InvalidTenantException;
use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use Tests\Fixtures\Models\TenancyTestParent;

// === Update/Upsert Guards ===

test('query builder update with tenant_id throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    expect(function () use ($tenantB) {
        TenancyTestParent::query()->update(['tenant_id' => $tenantB->id]);
    })->toThrow(InvalidTenantException::class);
});

test('query builder update with uppercase TENANT_ID throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    expect(function () use ($tenantB) {
        TenancyTestParent::query()->update(['TENANT_ID' => $tenantB->id]);
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

// === Insert Guards ===

test('insert without tenant context throws MissingTenantContextException', function () {
    expect(function () {
        TenancyTestParent::query()->insert([
            ['name' => 'X', 'slug' => 'x'],
        ]);
    })->toThrow(MissingTenantContextException::class);
});

test('insert with foreign tenant_id throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    expect(function () use ($tenantA, $tenantB) {
        inTenant($tenantA, function () use ($tenantB) {
            TenancyTestParent::query()->insert([
                ['tenant_id' => $tenantB->id, 'name' => 'X', 'slug' => 'x'],
            ]);
        });
    })->toThrow(InvalidTenantException::class);
});

test('insert without tenant_id auto-fills from context', function () {
    $tenant = Tenant::factory()->create();

    inTenant($tenant, function () {
        TenancyTestParent::query()->insert([
            ['name' => 'Auto', 'slug' => 'auto'],
        ]);
    });

    $model = inTenant($tenant, fn () => TenancyTestParent::where('name', 'Auto')->first());

    expect($model)->not->toBeNull()
        ->and($model->tenant_id)->toBe($tenant->id);
});

test('insertGetId auto-fills tenant_id from context', function () {
    $tenant = Tenant::factory()->create();

    $id = inTenant($tenant, function () {
        return TenancyTestParent::query()->insertGetId([
            'name' => 'GetId',
            'slug' => 'getid',
        ]);
    });

    $model = inTenant($tenant, fn () => TenancyTestParent::find($id));

    expect($model->tenant_id)->toBe($tenant->id);
});

test('insertOrIgnore auto-fills tenant_id from context', function () {
    $tenant = Tenant::factory()->create();

    inTenant($tenant, function () {
        TenancyTestParent::query()->insertOrIgnore([
            ['name' => 'OrIgnore', 'slug' => 'orignore'],
        ]);
    });

    $model = inTenant($tenant, fn () => TenancyTestParent::where('name', 'OrIgnore')->first());

    expect($model->tenant_id)->toBe($tenant->id);
});

// === Increment/Decrement Guards ===

test('increment with tenant_id in extra throws InvalidTenantException', function () {
    $tenant = Tenant::factory()->create();
    $model = inTenant($tenant, fn () => TenancyTestParent::factory()->create());

    expect(function () use ($tenant) {
        inTenant($tenant, function () {
            TenancyTestParent::query()->increment('id', 1, ['tenant_id' => 999]);
        });
    })->toThrow(InvalidTenantException::class);
});

test('decrement with tenant_id in extra throws InvalidTenantException', function () {
    $tenant = Tenant::factory()->create();
    $model = inTenant($tenant, fn () => TenancyTestParent::factory()->create());

    expect(function () use ($tenant) {
        inTenant($tenant, function () {
            TenancyTestParent::query()->decrement('id', 1, ['tenant_id' => 999]);
        });
    })->toThrow(InvalidTenantException::class);
});

test('normal increment works and respects tenant scope', function () {
    $tenant = Tenant::factory()->create();
    $model = inTenant($tenant, fn () => TenancyTestParent::factory()->create());

    inTenant($tenant, function () use ($model) {
        TenancyTestParent::find($model->id)->increment('id', 0);
    });

    expect($model->exists)->toBeTrue();
});

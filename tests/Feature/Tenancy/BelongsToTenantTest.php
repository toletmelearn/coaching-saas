<?php

use App\Exceptions\InvalidTenantException;
use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Models\TenancyTestChild;
use Tests\Fixtures\Models\TenancyTestParent;

beforeEach(function () {
    // Run test fixture migrations. These are run AFTER RefreshDatabase has already refreshed
    // the main database, so they execute outside the transaction boundary.
    // This is safe even on MySQL because we're adding to an already-fresh database.
    Artisan::call('migrate', [
        '--path' => 'tests/Fixtures/migrations',
        '--realpath' => true,
    ]);
});

// === Tenant Isolation Tests ===

test('tenant A cannot see tenant B rows via get()', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => TenancyTestParent::factory()->count(3)->create());
    $tenantBRows = inTenant($tenantB, fn () => TenancyTestParent::factory()->count(2)->create());

    $allRowsInB = inTenant($tenantB, fn () => TenancyTestParent::all());

    $expectedIds = $tenantBRows->pluck('id')->sort()->values()->toArray();
    $actualIds = $allRowsInB->pluck('id')->sort()->values()->toArray();

    expect($allRowsInB)->toHaveCount(2)
        ->and($actualIds)->toBe($expectedIds);
});

test('tenant A cannot see tenant B rows via find()', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $rowA = inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    $foundInB = inTenant($tenantB, fn () => TenancyTestParent::find($rowA->id));

    expect($foundInB)->toBeNull();
});

test('tenant A cannot see tenant B rows via first()', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    $firstInB = inTenant($tenantB, fn () => TenancyTestParent::first());

    expect($firstInB)->toBeNull();
});

test('tenant A cannot see tenant B rows via count()', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => TenancyTestParent::factory()->count(5)->create());

    $countInB = inTenant($tenantB, fn () => TenancyTestParent::count());

    expect($countInB)->toBe(0);
});

test('tenant A cannot see tenant B rows via paginate()', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => TenancyTestParent::factory()->count(5)->create());
    inTenant($tenantB, fn () => TenancyTestParent::factory()->count(3)->create());

    $pageInB = inTenant($tenantB, fn () => TenancyTestParent::paginate(10));

    expect($pageInB->total())->toBe(3);
});

// === Missing Context Tests ===

test('querying without tenant context throws MissingTenantContextException', function () {
    expect(fn () => TenancyTestParent::all())->toThrow(MissingTenantContextException::class);
});

test('finding without tenant context throws MissingTenantContextException', function () {
    expect(fn () => TenancyTestParent::find(1))->toThrow(MissingTenantContextException::class);
});

// === Creation Tests ===

test('creating a model auto-sets tenant_id from context', function () {
    $tenant = Tenant::factory()->create();

    $model = inTenant($tenant, function () {
        $m = TenancyTestParent::make(['name' => 'Test', 'slug' => 'test']);
        expect($m->tenant_id)->toBeNull(); // not set yet on make

        $m->save();

        return $m;
    });

    expect($model->tenant_id)->toBe($tenant->id);
    expect($model->name)->toBe('Test');
});

test('creating a model with mismatched tenant_id throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    expect(fn () => inTenant($tenantA, function () use ($tenantB) {
        $model = TenancyTestParent::make([
            'name' => 'Test',
            'slug' => 'test',
        ]);
        // Directly set tenant_id to bypass guarding (to test the validation)
        $model->setAttribute('tenant_id', $tenantB->id);
        $model->forceFill(['tenant_id' => $tenantB->id]); // Bypass guarding to test validation
        $model->save();
    }))->toThrow(InvalidTenantException::class);
});

test('creating without tenant context throws MissingTenantContextException', function () {
    expect(fn () => TenancyTestParent::create([
        'name' => 'Test',
        'slug' => 'test',
    ]))->toThrow(MissingTenantContextException::class);
});

test('direct instantiation and save() auto-fills tenant_id from context', function () {
    $tenant = Tenant::factory()->create();

    $model = inTenant($tenant, function () {
        $m = new TenancyTestParent(['name' => 'Direct', 'slug' => 'direct']);
        expect($m->tenant_id)->toBeNull(); // not set yet

        $m->save();

        return $m;
    });

    expect($model->tenant_id)->toBe($tenant->id);
    expect($model->name)->toBe('Direct');
});

test('::create() auto-fills tenant_id from context', function () {
    $tenant = Tenant::factory()->create();

    $model = inTenant($tenant, fn () => TenancyTestParent::create([
        'name' => 'Created',
        'slug' => 'created',
    ]));

    expect($model->tenant_id)->toBe($tenant->id);
    expect($model->name)->toBe('Created');
});

test('::factory()->create() auto-fills tenant_id from context', function () {
    $tenant = Tenant::factory()->create();

    $model = inTenant($tenant, fn () => TenancyTestParent::factory()->create([
        'name' => 'Factory',
    ]));

    expect($model->tenant_id)->toBe($tenant->id);
    expect($model->name)->toBe('Factory');
});

test('tenant_id is not mass-assignable', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    inTenant($tenant, function () use ($tenant, $otherTenant) {
        $model = TenancyTestParent::make([
            'tenant_id' => $otherTenant->id,
            'name' => 'Test',
            'slug' => 'test',
        ]);

        // tenant_id is guarded, so the provided value is ignored
        expect($model->tenant_id)->toBeNull(); // not set by make() due to guarding

        $model->save();

        // After save(), tenant_id is auto-filled from context
        expect($model->tenant_id)->toBe($tenant->id); // auto-filled to context tenant
    });
});

// === Immutability Tests ===

test('changing tenant_id on update throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $model = inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    expect(fn () => inTenant($tenantA, function () use ($model, $tenantB) {
        // Use setAttribute + save to trigger the updating hook (update() uses query builder)
        $model->setAttribute('tenant_id', $tenantB->id);
        $model->save();
    }))->toThrow(InvalidTenantException::class);
});

test('saving a model instance with mismatched tenant_id throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $model = inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    expect(fn () => inTenant($tenantA, function () use ($model, $tenantB) {
        $model->tenant_id = $tenantB->id;
        $model->save();
    }))->toThrow(InvalidTenantException::class);
});

test('deleting a model instance with mismatched tenant_id throws InvalidTenantException', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $model = inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    expect(fn () => inTenant($tenantA, function () use ($model, $tenantB) {
        $model->tenant_id = $tenantB->id;
        $model->delete();
    }))->toThrow(InvalidTenantException::class);
});

// === Escape Hatch Tests ===

test('runAs() sets context and executes closure', function () {
    $tenant = Tenant::factory()->create();

    $model = app(TenantContext::class)->runAs($tenant, function () {
        return TenancyTestParent::factory()->create(['name' => 'Test']);
    });

    expect($model->tenant_id)->toBe($tenant->id);
    expect($model->name)->toBe('Test');
});

test('runAs() clears context in finally block even if closure throws', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    try {
        $context->runAs($tenant, function () {
            throw new Exception('Test exception');
        });
    } catch (Exception) {
        // catch expected exception
    }

    expect($context->has())->toBeFalse();
});

test('runAs() throws if a tenant is already set', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    expect(fn () => inTenant($tenant, function () use ($otherTenant) {
        app(TenantContext::class)->runAs($otherTenant, fn () => null);
    }))->toThrow(RuntimeException::class);
});

test('runAs() works without prior context (simulating console command)', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    expect($context->has())->toBeFalse();

    $model = $context->runAs($tenant, function () {
        return TenancyTestParent::factory()->create();
    });

    expect($model->tenant_id)->toBe($tenant->id);
    expect($context->has())->toBeFalse(); // cleared after
});

// === Composite FK Tests ===

test('inserting child row with mismatched tenant_id fails with FK constraint error', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $parent = inTenant($tenantA, fn () => TenancyTestParent::factory()->create());

    // Try to create child in tenant B pointing to parent in tenant A
    expect(fn () => inTenant($tenantB, function () use ($parent) {
        TenancyTestChild::create([
            'tenant_id' => app(TenantContext::class)->id(),
            'parent_id' => $parent->id,
            'name' => 'Child',
        ]);
    }))->toThrow(Exception::class); // FK constraint or other DB error
});

test('unique constraint on tenant_id and slug allows same slug in different tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => TenancyTestParent::factory()->create(['slug' => 'shared']));

    // Should not throw, same slug in different tenant is allowed
    inTenant($tenantB, fn () => TenancyTestParent::factory()->create(['slug' => 'shared']));

    $aCount = inTenant($tenantA, fn () => TenancyTestParent::where('slug', 'shared')->count());
    $bCount = inTenant($tenantB, fn () => TenancyTestParent::where('slug', 'shared')->count());

    expect($aCount)->toBe(1)
        ->and($bCount)->toBe(1);
});

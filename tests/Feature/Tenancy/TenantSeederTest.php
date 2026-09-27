<?php

use App\Models\Tenant;
use App\Models\TenantDomain;
use Database\Seeders\TenantSeeder;

test('the seeder creates one demo tenant with a verified subdomain', function () {
    $this->seed(TenantSeeder::class);

    $domain = TenantDomain::query()->where('domain', 'demo.coaching.test')->first();

    expect($domain)->not->toBeNull()
        ->and($domain->is_primary)->toBeTrue()
        ->and($domain->verified_at)->not->toBeNull()
        ->and(Tenant::query()->count())->toBe(1);
});

test('running the seeder twice does not create duplicate tenants', function () {
    $this->seed(TenantSeeder::class);
    $this->seed(TenantSeeder::class);

    expect(Tenant::query()->count())->toBe(1)
        ->and(TenantDomain::query()->count())->toBe(1);
});

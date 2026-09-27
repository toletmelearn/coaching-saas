<?php

use App\Enums\DomainType;
use App\Models\Tenant;
use App\Models\TenantDomain;

test('a tenant domain belongs to a tenant', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant)->create();

    expect($domain->tenant->id)->toBe($tenant->id)
        ->and($tenant->domains()->first()->id)->toBe($domain->id);
});

test('domain is normalized to lowercase without a trailing dot', function () {
    $domain = TenantDomain::factory()->create(['domain' => 'DEMO.Coaching.Test.']);

    expect($domain->domain)->toBe('demo.coaching.test');
});

test('domain type casts to the DomainType enum', function () {
    $domain = TenantDomain::factory()->create(['type' => 'custom']);

    expect($domain->fresh()->type)->toBe(DomainType::Custom);
});

test('a tenant cannot be deleted while a domain still references it', function () {
    $domain = TenantDomain::factory()->create();

    expect(fn () => $domain->tenant->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

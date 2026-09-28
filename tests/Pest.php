<?php

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

function inTenant(Tenant $tenant, callable $fn): mixed
{
    return app(TenantContext::class)->runAs($tenant, $fn);
}

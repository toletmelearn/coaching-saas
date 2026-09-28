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

/**
 * Simulate the boundary between two real, separate HTTP requests: clears every
 * guard's in-memory cached user (as a fresh production process would have), while
 * leaving session data untouched. Call this between two $this->post()/get() calls
 * in the same test whenever the second call must resolve auth fresh from the
 * session (through the guard's user provider) rather than reusing whatever the
 * first call already resolved and cached.
 */
function freshRequestCycle(): void
{
    app('auth')->forgetGuards();
}

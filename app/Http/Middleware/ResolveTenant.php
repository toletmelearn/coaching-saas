<?php

namespace App\Http\Middleware;

use App\Models\TenantDomain;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $context->reset();

        try {
            $host = rtrim(strtolower($request->getHost()), '.');

            if (in_array($host, config('tenancy.central_domains', []), true)) {
                return $next($request);
            }

            $tenantDomain = TenantDomain::query()->where('domain', $host)->first();

            if ($tenantDomain === null) {
                abort(404);
            }

            $context->set($tenantDomain->tenant);

            return $next($request);
        } finally {
            // Bracket the context tightly to this single request's lifecycle. In
            // production (one process per request) this is a no-op; it matters when
            // the container persists across requests (Octane, and this test suite's
            // HTTP client), so the next request — or a test's direct inTenant() call —
            // never inherits this request's tenant.
            $context->reset();
        }
    }
}

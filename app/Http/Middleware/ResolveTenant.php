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
        $host = rtrim(strtolower($request->getHost()), '.');

        if (in_array($host, config('tenancy.central_domains', []), true)) {
            return $next($request);
        }

        $tenantDomain = TenantDomain::query()->where('domain', $host)->first();

        if ($tenantDomain === null) {
            abort(404);
        }

        app(TenantContext::class)->set($tenantDomain->tenant);

        return $next($request);
    }
}

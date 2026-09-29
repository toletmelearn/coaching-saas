<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended (not prepended) to the 'web' group, so it runs after Laravel's own session
 * middleware — unlike ResolveTenant (prepended, runs before session starts), this needs
 * a working session to log a suspended tenant's logged-in user out. A no-op for central
 * domains (no TenantContext) and for every non-suspended tenant.
 */
class CheckTenantSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        if (! $context->has() || $context->get()->status !== TenantStatus::Suspended) {
            return $next($request);
        }

        if (Auth::guard('tenant')->check()) {
            Auth::guard('tenant')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->view('errors.tenant-suspended', [], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}

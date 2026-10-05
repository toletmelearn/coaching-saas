<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An impersonated owner session (started by ImpersonationController) ends 60 minutes after it
 * began. Once expired, the tenant guard is logged out and the browser is sent to login.
 */
class EnforceImpersonationExpiry
{
    private const LIFETIME_MINUTES = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $impersonation = $request->session()->get('impersonation');

        if (is_array($impersonation)
            && Carbon::createFromTimestamp($impersonation['started_at'])->addMinutes(self::LIFETIME_MINUTES)->isPast()) {
            AdminAuditLog::record(
                'impersonation_expired',
                'user',
                Auth::guard('tenant')->id(),
                (int) $impersonation['admin_id'],
                $request->ip(),
            );

            Auth::guard('tenant')->logout();
            $request->session()->forget('impersonation');
            $request->session()->invalidate();

            return redirect('/login');
        }

        return $next($request);
    }
}

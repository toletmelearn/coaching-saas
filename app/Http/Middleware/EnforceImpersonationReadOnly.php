<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An impersonated owner session is read-only. The rule is judged on the real HTTP method
 * (getRealMethod), so a _method override in a form body cannot turn a refused write into an allowed
 * one. Only POST /logout and POST /impersonation/exit may write. Student-data export and the
 * credentials-sheet download are GETs, so they are refused by path. Every refusal is audited.
 */
class EnforceImpersonationReadOnly
{
    private const BLOCKED_READS = ['manage/students/*/data/export', 'users/import/sheet/*/download'];

    private const ALLOWED_WRITES = ['logout', 'impersonation/exit'];

    public function handle(Request $request, Closure $next): Response
    {
        $impersonation = $request->session()->get('impersonation');

        if (! is_array($impersonation)) {
            return $next($request);
        }

        $safeMethod = in_array($request->getRealMethod(), ['GET', 'HEAD'], true);

        if ($safeMethod && ! $request->is(self::BLOCKED_READS)) {
            return $next($request);
        }

        if (! $safeMethod && $request->getRealMethod() === 'POST' && $request->is(self::ALLOWED_WRITES)) {
            return $next($request);
        }

        AdminAuditLog::record(
            'impersonation_blocked',
            'user',
            Auth::guard('tenant')->id(),
            (int) $impersonation['admin_id'],
            $request->ip(),
        );

        abort(403);
    }
}

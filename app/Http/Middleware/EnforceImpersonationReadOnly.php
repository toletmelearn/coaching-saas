<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An impersonated owner session is read-only and fails closed. The rule is judged on the real HTTP
 * method (getRealMethod), so a _method override cannot turn a refused write into an allowed one.
 *
 * Reads: only the paths in ALLOWED_READS, which are the student-facing pages an owner may browse
 * while checking how an account looks. Everything else is refused by default, so a new admin page,
 * the student-data export, and the credentials sheet (page, download and clear) all stay closed
 * unless someone adds them to the list on purpose.
 *
 * Writes: only POST /logout and POST /impersonation/exit.
 *
 * Every refusal is audited with the impersonating admin's id.
 */
class EnforceImpersonationReadOnly
{
    private const ALLOWED_READS = [
        'dashboard',
        'courses',
        'courses/*',
        'live-classes/*',
    ];

    // Denied even when they match ALLOWED_READS: the join redirect is a GET but
    // it opens an attendance row — impersonation must be read-only.
    private const DENIED_READS = [
        'live-classes/*/join',
    ];

    private const ALLOWED_WRITES = ['logout', 'impersonation/exit'];

    public function handle(Request $request, Closure $next): Response
    {
        $impersonation = $request->session()->get('impersonation');

        if (! is_array($impersonation)) {
            return $next($request);
        }

        $method = $request->getRealMethod();

        if (in_array($method, ['GET', 'HEAD'], true)
            && $request->is(self::ALLOWED_READS)
            && ! $request->is(self::DENIED_READS)) {
            return $next($request);
        }

        if ($method === 'POST' && $request->is(self::ALLOWED_WRITES)) {
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

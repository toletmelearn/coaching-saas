<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive owner actions (changing the password, erasing a student) are refused outright while
 * the session is an impersonated one — a platform admin must never change an owner's credentials
 * or destroy student data as that owner.
 */
class BlockWhileImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->session()->has('impersonation'), 403);

        return $next($request);
    }
}

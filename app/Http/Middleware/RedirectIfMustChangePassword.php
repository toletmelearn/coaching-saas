<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfMustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('tenant')->user();

        if ($user !== null && $user->must_change_password) {
            return redirect('/auth/change-password');
        }

        return $next($request);
    }
}

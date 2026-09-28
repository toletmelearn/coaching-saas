<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('tenant')->user();

        if ($user !== null && $user->status !== 'active') {
            Auth::guard('tenant')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login');
        }

        return $next($request);
    }
}

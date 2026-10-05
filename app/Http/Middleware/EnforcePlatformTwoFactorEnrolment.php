<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * In production, a platform admin without a TOTP secret is sent to enrol before reaching any
 * other admin page. Outside production enrolment stays optional.
 */
class EnforcePlatformTwoFactorEnrolment
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform_admin')->user();

        if (app()->isProduction() && $admin !== null && ! $admin->hasTwoFactor()) {
            return redirect('/admin/two-factor/setup');
        }

        return $next($request);
    }
}

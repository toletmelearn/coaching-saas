<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class PlatformAdminLoginController extends Controller
{
    public function show(): View
    {
        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Platform admin login only exists on the central domain — never a tenant subdomain.
        if (app(TenantContext::class)->has()) {
            return back()->withErrors(['email' => __('auth.failed')]);
        }

        $key = sprintf('admin-login:%s:%s', strtolower(trim($data['email'])), $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429);
        }

        if (! Auth::guard('platform_admin')->attempt($data)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();

        $admin = Auth::guard('platform_admin')->user();
        $admin->forceFill(['last_login_at' => now()])->save();

        return redirect('/admin/dashboard');
    }
}

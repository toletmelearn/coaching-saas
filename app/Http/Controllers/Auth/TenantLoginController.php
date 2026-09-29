<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class TenantLoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $tenant = app(TenantContext::class)->get();
        $identifier = trim($data['identifier']);
        // Per tenant + identifier + IP: stops brute-forcing a single account from one
        // machine.
        $ipKey = sprintf('login:%d:%s:%s', $tenant->id, strtolower($identifier), $request->ip());
        // Per tenant + identifier only, regardless of IP: stops the same attack distributed
        // across many IPs (a botnet, or rotating proxies), which the IP-scoped limiter alone
        // never catches since each IP individually stays under its own threshold. See
        // SECURITY.md "Rate limiting".
        $identifierKey = sprintf('login-identifier:%d:%s', $tenant->id, strtolower($identifier));

        if (RateLimiter::tooManyAttempts($ipKey, 5) || RateLimiter::tooManyAttempts($identifierKey, 20)) {
            abort(429);
        }

        $user = str_contains($identifier, '@')
            ? User::query()->where('email', strtolower($identifier))->first()
            : User::query()->where('phone', User::normalizePhone($identifier))->first();

        if ($user === null || $user->status !== UserStatus::Active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($identifierKey, 60 * 60);

            return back()->withErrors(['identifier' => __('auth.failed')]);
        }

        RateLimiter::clear($ipKey);
        RateLimiter::clear($identifierKey);

        $request->session()->regenerate();

        Auth::guard('tenant')->login($user, $request->boolean('remember'));

        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->must_change_password) {
            return redirect('/auth/change-password');
        }

        return redirect('/dashboard');
    }
}

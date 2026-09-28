<?php

namespace App\Http\Controllers\Auth;

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
        $key = sprintf('login:%d:%s:%s', $tenant->id, strtolower($identifier), $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429);
        }

        $user = str_contains($identifier, '@')
            ? User::query()->where('email', strtolower($identifier))->first()
            : User::query()->where('phone', User::normalizePhone($identifier))->first();

        if ($user === null || $user->status !== 'active' || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['identifier' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();

        Auth::guard('tenant')->login($user, $request->boolean('remember'));

        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->must_change_password) {
            return redirect('/auth/change-password');
        }

        return redirect('/dashboard');
    }
}

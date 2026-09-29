<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LoginRateLimiter;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TenantLoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login', ['tenant' => app(TenantContext::class)->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $tenant = app(TenantContext::class)->get();
        $identifier = trim($data['identifier']);

        if (LoginRateLimiter::tooManyAttempts($tenant->id, $identifier, $request->ip())) {
            abort(429);
        }

        $user = str_contains($identifier, '@')
            ? User::query()->where('email', strtolower($identifier))->first()
            : User::query()->where('phone', User::normalizePhone($identifier))->first();

        if ($user === null || $user->status !== UserStatus::Active || ! Hash::check($data['password'], $user->password)) {
            LoginRateLimiter::hit($tenant->id, $identifier, $request->ip());

            return back()->withErrors(['identifier' => __('auth.failed')]);
        }

        LoginRateLimiter::clearSuccessfulLogin($tenant->id, $identifier);

        $request->session()->regenerate();

        Auth::guard('tenant')->login($user, $request->boolean('remember'));

        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->must_change_password) {
            return redirect('/auth/change-password');
        }

        return redirect('/dashboard');
    }
}

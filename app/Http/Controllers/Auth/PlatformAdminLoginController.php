<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\PlatformAdmin;
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

        if (! Auth::guard('platform_admin')->validate($data)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        // An admin with a TOTP secret proves the password here but is not logged in until
        // the second factor passes (PlatformAdminTwoFactorController::verify).
        $admin = PlatformAdmin::where('email', $data['email'])->firstOrFail();

        if ($admin->hasTwoFactor()) {
            $request->session()->put('admin_2fa_pending', ['admin_id' => $admin->id, 'at' => now()->timestamp]);

            return redirect('/admin/two-factor');
        }

        Auth::guard('platform_admin')->login($admin);
        $request->session()->regenerate();

        $admin->forceFill(['last_login_at' => now()])->save();

        // Phase 13: every privileged session start lands in the audit trail.
        AdminAuditLog::record('login', adminId: $admin->id, ipAddress: $request->ip());

        return redirect('/admin/dashboard');
    }
}

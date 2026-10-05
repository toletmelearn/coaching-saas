<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\PlatformAdmin;
use App\Support\Auth\Totp;
use App\Support\Auth\TwoFactorVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * TOTP second factor for platform admins. A password proves who is asking; the pending state
 * (5 minutes, session-held) proves they have passed that step; only a valid, unreplayed code or an
 * unused recovery code completes the login. Failed attempts are audited, and 20 of them within an
 * hour lock verification for that admin for an hour. The lock is keyed by admin, so a fresh password
 * login in a new session does not clear it.
 */
class PlatformAdminTwoFactorController extends Controller
{
    private const PENDING_MINUTES = 5;

    private const ATTEMPTS_PER_MINUTE = 5;

    private const LOCK_THRESHOLD = 20;

    private const LOCK_WINDOW_MINUTES = 60;

    private const RECOVERY_CODE_COUNT = 8;

    public function challenge(Request $request): View|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return redirect('/admin/login');
        }

        return view('admin.two-factor');
    }

    public function verify(Request $request, TwoFactorVerifier $verifier): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect('/admin/login');
        }

        $admin = PlatformAdmin::find($pending['admin_id']);

        if ($admin === null || Carbon::createFromTimestamp($pending['at'])->addMinutes(self::PENDING_MINUTES)->isPast()) {
            $request->session()->forget('admin_2fa_pending');

            throw ValidationException::withMessages(['code' => __('admin_2fa.expired')]);
        }

        $key = 'admin-2fa:'.$admin->id;
        abort_if(RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE), 429);
        RateLimiter::hit($key, 60);

        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $code = trim($data['code']);

        if ($this->locked($admin)) {
            $this->audit('two_factor_locked', $admin, $request);

            throw ValidationException::withMessages(['code' => __('admin_2fa.locked')]);
        }

        if ($verifier->acceptTotp($admin->id, $code, time()) || $verifier->consumeRecoveryCode($admin->id, $code)) {
            return $this->complete($request, $admin);
        }

        $this->audit('two_factor_failed', $admin, $request);

        throw ValidationException::withMessages(['code' => __('admin_2fa.invalid')]);
    }

    public function setup(Request $request): View
    {
        $admin = Auth::guard('platform_admin')->user();

        $secret = $request->session()->get('admin_2fa_setup_secret');
        if ($secret === null) {
            $secret = Totp::generateSecret();
            $request->session()->put('admin_2fa_setup_secret', $secret);
        }

        $uri = 'otpauth://totp/'.rawurlencode('Coaching SaaS:'.$admin->email)
            .'?secret='.$secret.'&issuer='.rawurlencode('Coaching SaaS');

        return view('admin.two-factor-setup', ['secret' => $secret, 'uri' => $uri]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        $admin = Auth::guard('platform_admin')->user();
        $secret = (string) $request->session()->get('admin_2fa_setup_secret');

        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $step = $secret === '' ? null : Totp::matchStep($secret, trim($data['code']), time());

        if ($step === null) {
            throw ValidationException::withMessages(['code' => __('admin_2fa.invalid')]);
        }

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        $admin->forceFill([
            'totp_secret' => $secret,
            'totp_enabled_at' => now(),
            'totp_last_step' => $step,
            'recovery_codes' => array_map(fn (string $code): string => Hash::make($code), $codes),
        ])->save();

        $request->session()->forget('admin_2fa_setup_secret');

        return redirect('/admin/dashboard')->with('recovery_codes', $codes);
    }

    /**
     * @return array{admin_id: int, at: int}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get('admin_2fa_pending');

        return is_array($pending) ? $pending : null;
    }

    /**
     * The lock counts `two_factor_failed` rows in admin_audit_logs for this admin within the last hour.
     * Any retention job for that table must keep rows newer than one hour, or the lock silently lifts early.
     */
    private function locked(PlatformAdmin $admin): bool
    {
        $failures = AdminAuditLog::query()
            ->where('admin_id', $admin->id)
            ->where('action', 'two_factor_failed')
            ->where('created_at', '>=', now()->subMinutes(self::LOCK_WINDOW_MINUTES))
            ->count();

        return $failures >= self::LOCK_THRESHOLD;
    }

    private function audit(string $action, PlatformAdmin $admin, Request $request): void
    {
        AdminAuditLog::record($action, 'admin', $admin->id, $admin->id, $request->ip());
    }

    private function complete(Request $request, PlatformAdmin $admin): RedirectResponse
    {
        Auth::guard('platform_admin')->login($admin);
        $request->session()->forget('admin_2fa_pending');
        $request->session()->regenerate();

        $admin->forceFill(['last_login_at' => now()])->save();
        AdminAuditLog::record('login', adminId: $admin->id, ipAddress: $request->ip());

        return redirect('/admin/dashboard');
    }
}

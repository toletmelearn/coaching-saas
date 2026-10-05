<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * The tenant-host half of "Login as owner" (Phase 13, feature E).
 *
 * Reached only through a URL the platform admin just minted on
 * /admin/institutes/{tenant}/login-as; the `signed` middleware has already
 * verified the HMAC over the full `https://<this-host>/admin/impersonate?...`
 * URL (so a link minted for tenant A dies on tenant B's host) and its expiry
 * (60 seconds). Three further layers:
 *
 *  - single use: Cache::add burns the link on first touch — a replay 403s;
 *  - tenant scope: the user id resolves through TenantScope against the host's
 *    tenant, so another tenant's user is a 404, never a login;
 *  - role: only owners are impersonatable — the mint endpoint only ever signs
 *    owner ids anyway.
 *
 * The audit row is written BEFORE the session is created: an impersonation that
 * somehow failed to log must also fail to happen. RegisterUserDevice (Phase 9)
 * ignores non-student logins, so the owner's real devices are untouched.
 */
class ImpersonationController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        $userId = (int) $request->query('user');
        $adminId = (int) $request->query('admin');
        $signature = (string) $request->query('signature');

        abort_unless(Cache::add("admin.impersonate.{$signature}", 1, 600), 403);

        $user = User::query()->whereKey($userId)->first();

        abort_unless($user !== null, 404);
        abort_unless($user->role === UserRole::Owner, 403);

        AdminAuditLog::record('impersonate', 'user', $user->id, $adminId, $request->ip());

        Auth::guard('tenant')->login($user);
        $request->session()->regenerate();

        $request->session()->put('impersonation', ['admin_id' => $adminId, 'started_at' => now()->timestamp]);

        return redirect('/dashboard');
    }
}

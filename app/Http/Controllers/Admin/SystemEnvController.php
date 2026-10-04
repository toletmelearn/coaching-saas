<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\Admin\ArtisanRunner;
use App\Support\Admin\EnvFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * /admin/settings/system (Phase 13, feature G) — allowlisted .env editor plus
 * the Clear cache / Optimize buttons, so routine deploy chores need no shell.
 *
 * Allowlist policy lives here (approved set): operational knobs an operator
 * legitimately tunes. Excluded on purpose and never writable through this
 * endpoint: APP_KEY (rotates crypto/cookies), DB_* (would cut the app off from
 * its own data mid-edit), APP_ENV and APP_DEBUG (would disable the production
 * gate itself — APP_DEBUG is only ever repairable through the APP_DEBUG-gated
 * /admin/health fix flow). A key outside the allowlist is a validation
 * exception, not a silent skip.
 *
 * Newlines in values are rejected (a newline would append a second KEY= line —
 * env injection); EnvFile re-checks independently and writes atomically.
 * Blank values mean "keep current", so a form round-trip can never wipe a key.
 *
 * .env writes take effect through config:cache state — the page says so, and
 * the two buttons drive exactly that: optimize:clear (drop cached config/
 * routes/app cache) and optimize (rebuild them from the edited file).
 * Everything goes through ArtisanRunner so tests record instead of executing.
 */
class SystemEnvController extends Controller
{
    /** The editable allowlist (decision 4.2, docs/specs/phase-13-admin-panel.md). */
    public const ALLOWED = [
        'APP_NAME',
        'APP_URL',
        'MAIL_MAILER',
        'MAIL_HOST',
        'MAIL_PORT',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'SESSION_LIFETIME',
        'SESSION_SECURE_COOKIE',
        'VIDEO_DRIVER',
        'PLATFORM_DOMAIN',
        'TENANT_BASE_DOMAIN',
        'CENTRAL_DOMAINS',
    ];

    public function show(EnvFile $env): View
    {
        return view('admin.settings.system', [
            'allowed' => self::ALLOWED,
            'values' => collect($env->all())->only(self::ALLOWED),
        ]);
    }

    public function store(Request $request, EnvFile $env): RedirectResponse
    {
        $data = $request->validate([
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:500', 'regex:/^[^\r\n]*$/'],
        ]);

        $changed = [];

        foreach ((array) ($data['values'] ?? []) as $key => $value) {
            $key = (string) $key;

            if (! in_array($key, self::ALLOWED, true)) {
                throw ValidationException::withMessages([
                    'values' => __('platform.admin.system.not_allowed', ['key' => $key]),
                ]);
            }

            if (! is_string($value) || $value === '' || $env->get($key) === $value) {
                continue;
            }

            $env->set($key, $value);
            $changed[] = $key;
        }

        foreach ($changed as $key) {
            AdminAuditLog::record(
                'update_setting',
                'env:'.$key,
                adminId: Auth::guard('platform_admin')->user()?->id,
                ipAddress: $request->ip(),
            );
        }

        return redirect('/admin/settings/system')
            ->with('status', __('platform.admin.system.saved'))
            ->with('changed', $changed);
    }

    public function clearCache(Request $request, ArtisanRunner $artisan): RedirectResponse
    {
        $result = $artisan->call('optimize:clear');

        AdminAuditLog::record(
            'clear_cache',
            adminId: Auth::guard('platform_admin')->user()?->id,
            ipAddress: $request->ip(),
        );

        return redirect('/admin/settings/system')->with(
            $result['code'] === 0 ? 'status' : 'error',
            __('platform.admin.system.cleared'),
        );
    }

    public function optimize(Request $request, ArtisanRunner $artisan): RedirectResponse
    {
        $result = $artisan->call('optimize');

        AdminAuditLog::record(
            'optimize',
            adminId: Auth::guard('platform_admin')->user()?->id,
            ipAddress: $request->ip(),
        );

        return redirect('/admin/settings/system')->with(
            $result['code'] === 0 ? 'status' : 'error',
            __('platform.admin.system.optimized'),
        );
    }
}

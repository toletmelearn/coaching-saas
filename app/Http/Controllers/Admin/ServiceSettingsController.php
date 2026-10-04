<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\Admin\ServiceSettings;
use App\Support\LiveClasses\JitsiJwt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * /admin/settings/services (Phase 13, feature A) — Bunny + Jitsi credentials
 * edited without touching .env.
 *
 * Secrets are encrypted at rest by ServiceSettings (never echoed back into any
 * response), a blank field means "keep the current value", and every write
 * lands in the audit log as update_setting / setting:<key>.
 *
 * "Test connection" semantics:
 *  - Bunny: a real GET https://api.bunny.net/videolibrary with the saved key —
 *    2xx means the key is valid for the account.
 *  - Jitsi: a local HS256 round-trip (mint + verify through JitsiJwt). JaaS
 *    exposes no unauthenticated endpoint to probe, and the credential's only
 *    job is signing join tokens — proving sign+verify round-trips is exactly
 *    the property that matters, with no secret leaving the process.
 */
class ServiceSettingsController extends Controller
{
    public function show(): View
    {
        return view('admin.settings.services', [
            'appId' => ServiceSettings::get('jitsi_app_id'),
            'configured' => [
                'bunny_account_api_key' => ServiceSettings::isConfigured('bunny_account_api_key'),
                'jitsi_app_id' => ServiceSettings::isConfigured('jitsi_app_id'),
                'jitsi_app_secret' => ServiceSettings::isConfigured('jitsi_app_secret'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bunny_account_api_key' => ['nullable', 'string', 'max:300'],
            'jitsi_app_id' => ['nullable', 'string', 'max:200'],
            'jitsi_app_secret' => ['nullable', 'string', 'max:300'],
        ]);

        foreach ($data as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue; // blank field = keep the current value
            }

            ServiceSettings::set($key, $value);

            AdminAuditLog::record(
                'update_setting',
                'setting:'.$key,
                adminId: Auth::guard('platform_admin')->user()?->id,
                ipAddress: $request->ip(),
            );
        }

        return redirect('/admin/settings/services')
            ->with('status', __('platform.admin.services.saved'));
    }

    public function testBunny(): RedirectResponse
    {
        $key = ServiceSettings::get('bunny_account_api_key');

        if ($key === null || $key === '') {
            return redirect('/admin/settings/services')->with('bunny_test', 'missing');
        }

        try {
            $ok = Http::withHeaders(['AccessKey' => $key])
                ->timeout(5)
                ->get('https://api.bunny.net/videolibrary')
                ->successful();
        } catch (ConnectionException) {
            $ok = false;
        }

        return redirect('/admin/settings/services')->with('bunny_test', $ok ? 'ok' : 'fail');
    }

    public function testJitsi(): RedirectResponse
    {
        $appId = ServiceSettings::get('jitsi_app_id');
        $secret = ServiceSettings::get('jitsi_app_secret');

        if ($appId === null || $appId === '' || $secret === null || $secret === '') {
            return redirect('/admin/settings/services')->with('jitsi_test', 'missing');
        }

        $token = JitsiJwt::make($appId, $secret, 'Connection test', 'test@example.invalid', 2);
        $ok = JitsiJwt::verify($token, $secret);

        return redirect('/admin/settings/services')->with('jitsi_test', $ok ? 'ok' : 'fail');
    }
}

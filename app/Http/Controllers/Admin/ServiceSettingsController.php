<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\Admin\ServiceSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * /admin/settings/services (Phase 13, feature A) — Bunny credentials edited
 * without touching .env. JaaS keys (jitsi_app_id / jitsi_app_secret) are
 * accepted by store() for back-compat but no longer rendered in the form
 * (Batch 2.5). Secrets are encrypted at rest, a blank field keeps the stored
 * value, and every write lands in the audit log.
 */
class ServiceSettingsController extends Controller
{
    public function show(): View
    {
        return view('admin.settings.services', [
            'configured' => [
                'bunny_account_api_key' => ServiceSettings::isConfigured('bunny_account_api_key'),
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

}

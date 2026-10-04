<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\Admin\ArtisanRunner;
use App\Support\Admin\EnvFile;
use App\Support\Preflight\PreflightChecks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * /admin/health (Phase 13, feature B) — the app:preflight checklist as a page.
 *
 * Re-run is inherent (every GET recomputes the checks). The Fix button is
 * double-gated on APP_DEBUG: hidden by default, and the endpoint aborts 403
 * when debug is off, so a production panel can never rewrite .env. Fix values
 * come from PreflightChecks' server-side fix map — the client only names the
 * failing check, it never supplies the value to write.
 */
class HealthController extends Controller
{
    public function index(): View
    {
        return view('admin.health', [
            'outcomes' => app(PreflightChecks::class)->run(),
            'fixable' => (bool) config('app.debug'),
        ]);
    }

    public function fix(Request $request, EnvFile $env, ArtisanRunner $artisan): RedirectResponse
    {
        abort_unless((bool) config('app.debug'), 403);

        $data = $request->validate([
            'check' => ['required', 'string', 'max:300'],
        ]);

        $outcome = collect(app(PreflightChecks::class)->run())
            ->firstWhere('id', $data['check']);

        // Passing checks carry no fix; unknown ids carry none either. Either way
        // this is not a repairable state — 404 rather than a silent no-op.
        abort_unless(is_array($outcome) && $outcome['fix'] !== null, 404);

        $env->set($outcome['fix']['key'], $outcome['fix']['value']);
        $artisan->call('config:clear');

        AdminAuditLog::record(
            'update_setting',
            'health_fix:'.$outcome['fix']['key'],
            adminId: Auth::guard('platform_admin')->user()?->id,
            ipAddress: $request->ip(),
        );

        return redirect('/admin/health')
            ->with('status', __('platform.admin.health.fixed', ['key' => $outcome['fix']['key']]));
    }
}

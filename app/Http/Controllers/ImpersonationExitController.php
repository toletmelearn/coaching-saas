<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends an impersonated owner session from the banner's Exit button, logging the owner out of
 * this browser entirely. Ordinary logout is the same as this for an impersonated session.
 */
class ImpersonationExitController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $impersonation = $request->session()->get('impersonation');

        if (is_array($impersonation)) {
            AdminAuditLog::record('impersonation_exit', 'user', Auth::guard('tenant')->id(), (int) $impersonation['admin_id'], $request->ip());
        }

        Auth::guard('tenant')->logout();
        $request->session()->forget('impersonation');
        $request->session()->invalidate();

        return redirect('/login');
    }
}

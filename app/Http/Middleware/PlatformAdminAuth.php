<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * Phase 13: `auth:platform_admin` for the control-panel routes, plus one
 * stricter rule for authenticated *tenant* sessions — a flat 403 instead of
 * the framework's redirect to /admin/login. Guests keep the exact redirect
 * they have always had (PlatformAdminGuardTest pins it).
 *
 * Why a subclass rather than a separate middleware listed before `auth`:
 * the router sorts resolved middleware against the priority list
 * (Illuminate\Foundation\Configuration\Middleware::$defaultPriority, which the
 * app extends via prependToPriorityList in bootstrap/app.php) AFTER resolving
 * names. `Authenticate` matches that list through its AuthenticatesRequests
 * interface, so a standalone middleware sitting after the `web` group gets
 * out-ranked: auth is spliced ahead of it, fails first with a redirect, and
 * the standalone check never runs (this is exactly the bug the first
 * implementation hit — see docs/specs/phase-13-admin-panel.md). By *being* the
 * auth middleware, the tenant check is carried to whatever slot sorting picks
 * for auth — which is after StartSession (so a real tenant session cookie is
 * readable) and before SubstituteBindings.
 *
 * The identity check runs before any platform-admin check: a tenant session on
 * a platform-only route is always a 403, never a partial credential.
 */
class PlatformAdminAuth extends Authenticate
{
    /**
     * @param  Request  $request
     * @param  array<int, string|null>  $guards
     * @return void
     */
    protected function authenticate($request, array $guards)
    {
        if ($request->user('tenant') !== null || $request->user('web') !== null) {
            abort(403);
        }

        parent::authenticate($request, $guards);
    }
}

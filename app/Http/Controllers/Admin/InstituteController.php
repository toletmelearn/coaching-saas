<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateInstituteAction;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Admin\BunnyUsage;
use App\Support\LoginRateLimiter;
use App\Support\PlatformStats;
use App\Support\TemporaryPasswordGenerator;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class InstituteController extends Controller
{
    public function index(Request $request, PlatformStats $stats): View
    {
        $search = trim((string) $request->query('q', ''));

        $query = Tenant::query()->with('domains');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('domains', fn ($d) => $d->where('domain', 'like', "%{$search}%"));
            });
        }

        $tenants = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.institutes.index', [
            'tenants' => $tenants,
            'search' => $search,
            'counts' => $stats->countsByTenant(),
            'owners' => $stats->ownerNamesByTenant(),
        ]);
    }

    public function create(): View
    {
        return view('admin.institutes.create');
    }

    public function store(Request $request, CreateInstituteAction $action): RedirectResponse
    {
        $result = $action->execute($request->only([
            'name', 'subdomain', 'owner_name', 'owner_email', 'owner_phone',
        ]));

        AdminAuditLog::record(
            'create_tenant',
            'tenant',
            $result['tenant']->id,
            Auth::guard('platform_admin')->user()?->id,
            $request->ip(),
        );

        return redirect(url('/admin/institutes/'.$result['tenant']->id))
            ->with('temporary_password', $result['temporary_password'])
            ->with('temporary_password_for', [
                'name' => $result['owner']->name,
                'identifier' => $result['owner']->email ?? $result['owner']->phone,
            ])
            ->with('login_url', "https://{$result['domain']}");
    }

    public function show(Tenant $tenant, PlatformStats $stats): View
    {
        $tenant->load('domains');
        $counts = $stats->countsByTenant();

        $owners = app(TenantContext::class)->runAs($tenant, fn () => User::query()->where('role', UserRole::Owner)->get());

        return view('admin.institutes.show', [
            'tenant' => $tenant,
            'students' => $counts[$tenant->id]['students'] ?? 0,
            'courses' => $counts[$tenant->id]['courses'] ?? 0,
            'owners' => $owners,
        ]);
    }

    public function suspend(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        AdminAuditLog::record(
            'suspend_tenant',
            'tenant',
            $tenant->id,
            Auth::guard('platform_admin')->user()?->id,
            $request->ip(),
        );

        return redirect(url('/admin/institutes/'.$tenant->id));
    }

    public function reactivate(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['status' => TenantStatus::Active])->save();

        AdminAuditLog::record(
            'reactivate_tenant',
            'tenant',
            $tenant->id,
            Auth::guard('platform_admin')->user()?->id,
            $request->ip(),
        );

        return redirect(url('/admin/institutes/'.$tenant->id));
    }

    /**
     * `owner_id` is only meaningful (and only rendered as a choice in the view) when the
     * institute has more than one owner — with a single owner the form omits it and this
     * falls back to that one owner, exactly as before.
     */
    public function resetOwnerPassword(Request $request, Tenant $tenant): RedirectResponse
    {
        $requestedOwnerId = $request->input('owner_id');
        $temporaryPassword = TemporaryPasswordGenerator::generate();

        $owner = app(TenantContext::class)->runAs($tenant, function () use ($requestedOwnerId, $temporaryPassword) {
            $query = User::query()->where('role', UserRole::Owner);
            $owner = $requestedOwnerId !== null
                ? $query->where('id', $requestedOwnerId)->first()
                : $query->first();

            if ($owner === null) {
                return null;
            }

            $owner->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
            ])->save();

            return $owner;
        });

        if ($owner === null) {
            return redirect(url('/admin/institutes/'.$tenant->id))
                ->withErrors(['owner' => __('platform.admin.institutes.show.no_owner')]);
        }

        LoginRateLimiter::clearForUser($tenant->id, $owner);

        AdminAuditLog::record(
            'reset_password',
            'user',
            $owner->id,
            Auth::guard('platform_admin')->user()?->id,
            $request->ip(),
        );

        return redirect(url('/admin/institutes/'.$tenant->id))
            ->with('temporary_password', $temporaryPassword)
            ->with('temporary_password_for', ['name' => $owner->name]);
    }

    /**
     * Phase 13 (feature E) — "Login as owner": mint a signed, single-use,
     * 60-second URL on the TENANT's host and hand the browser over to it.
     *
     * Why a cross-host redirect instead of logging in right here: SESSION_DOMAIN
     * is null (host-only cookies — SECURITY.md), so a session created while the
     * browser talks to the platform domain would be unusable on the tenant host.
     * The impersonation login must therefore execute inside a request that lands
     * on the tenant host; forceRootUrl points URL generation there so the HMAC
     * signature covers `https://<tenant>/admin/impersonate?...` — a signature
     * minted for one host cannot be replayed against another. The target route
     * (ImpersonationController) re-verifies signature + expiry + single use and
     * writes the audit row with the admin id carried inside the signed query.
     *
     * `owner_id` mirrors resetOwnerPassword: only meaningful with multiple
     * owners, otherwise the (first) owner is used.
     */
    public function loginAsOwner(Request $request, Tenant $tenant): RedirectResponse
    {
        $requestedOwnerId = $request->input('owner_id');

        $owner = app(TenantContext::class)->runAs($tenant, function () use ($requestedOwnerId) {
            $query = User::query()->where('role', UserRole::Owner);

            if ($requestedOwnerId !== null) {
                $query->where('id', $requestedOwnerId);
            }

            return $query->orderBy('id')->first();
        });

        if ($owner === null) {
            return redirect(url('/admin/institutes/'.$tenant->id))
                ->withErrors(['owner' => __('platform.admin.institutes.show.no_owner')]);
        }

        $domain = $tenant->domains()->orderByDesc('is_primary')->value('domain');
        abort_if($domain === null || $domain === '', 422);

        URL::forceRootUrl("https://{$domain}");

        try {
            $signed = URL::temporarySignedRoute('admin.impersonate', now()->addSeconds(60), [
                'user' => (int) $owner->id,
                'admin' => (int) Auth::guard('platform_admin')->id(),
            ]);
        } finally {
            URL::forceRootUrl(null);
        }

        return redirect($signed);
    }

    /**
     * Phase 13 (feature E) — Bunny usage for one tenant: live api.bunny.net
     * figures for that tenant's own library, plus the locally tracked totals.
     * Kept off show(): a slow or failing Bunny API must never break the page an
     * operator is already on.
     */
    public function bunnyUsage(Tenant $tenant, BunnyUsage $usage): View
    {
        return view('admin.institutes.bunny-usage', [
            'tenant' => $tenant,
            'usage' => $usage->forTenant($tenant),
        ]);
    }
}

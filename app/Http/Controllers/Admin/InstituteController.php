<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateInstituteAction;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LoginRateLimiter;
use App\Support\PlatformStats;
use App\Support\TemporaryPasswordGenerator;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        return redirect()->route('admin.institutes.show', $result['tenant'])
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

    public function suspend(Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        return redirect()->route('admin.institutes.show', $tenant);
    }

    public function reactivate(Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['status' => TenantStatus::Active])->save();

        return redirect()->route('admin.institutes.show', $tenant);
    }

    public function resetOwnerPassword(Tenant $tenant): RedirectResponse
    {
        $temporaryPassword = TemporaryPasswordGenerator::generate();

        $owner = app(TenantContext::class)->runAs($tenant, function () use ($temporaryPassword) {
            $owner = User::query()->where('role', UserRole::Owner)->first();

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
            return redirect()->route('admin.institutes.show', $tenant)
                ->withErrors(['owner' => __('platform.admin.institutes.show.no_owner')]);
        }

        LoginRateLimiter::clearForUser($tenant->id, $owner);

        return redirect()->route('admin.institutes.show', $tenant)
            ->with('temporary_password', $temporaryPassword)
            ->with('temporary_password_for', ['name' => $owner->name]);
    }
}

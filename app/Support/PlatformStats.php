<?php

namespace App\Support;

use App\Enums\DemoRequestStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\DemoRequest;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant counts, deliberately NOT going through tenant-owned models' Eloquent
 * scopes (which throw MissingTenantContextException with no ambient tenant — by design,
 * see TENANCY.md). Uses DB::table() aggregate queries grouped by tenant_id instead — the
 * one documented exception for system-level, cross-tenant reporting (TENANCY.md
 * "Database queries and raw SQL"). `Tenant` itself is not tenant-owned, so plain
 * Eloquent queries against it are fine.
 */
class PlatformStats
{
    /**
     * @return array{active_institutes: int, suspended_institutes: int, total_students: int, total_courses: int, new_demo_requests: int}
     */
    public function dashboardCounts(): array
    {
        return [
            'active_institutes' => Tenant::query()->where('status', TenantStatus::Active)->count(),
            'suspended_institutes' => Tenant::query()->where('status', TenantStatus::Suspended)->count(),
            // Raw query for a platform-wide cross-tenant count; verified to have no
            // tenant_id filter deliberately, since it must sum every tenant's students.
            'total_students' => DB::table('users')->where('role', UserRole::Student->value)->count(),
            // Raw query for a platform-wide cross-tenant count; same reasoning.
            'total_courses' => DB::table('courses')->count(),
            'new_demo_requests' => DemoRequest::query()->where('status', DemoRequestStatus::New)->count(),
        ];
    }

    /**
     * Per-institute student/course counts for the institutes list, computed with two
     * grouped aggregate queries (not N+1) and keyed by tenant_id.
     *
     * @return array<int, array{students: int, courses: int}>
     */
    public function countsByTenant(): array
    {
        // Raw query for a platform-wide cross-tenant report, grouped by tenant_id so
        // each tenant only ever sees its own aggregate below — never another tenant's.
        $students = DB::table('users')
            ->select('tenant_id', DB::raw('count(*) as aggregate'))
            ->where('role', UserRole::Student->value)
            ->groupBy('tenant_id')
            ->pluck('aggregate', 'tenant_id');

        // Raw query for a platform-wide cross-tenant report; same reasoning.
        $courses = DB::table('courses')
            ->select('tenant_id', DB::raw('count(*) as aggregate'))
            ->groupBy('tenant_id')
            ->pluck('aggregate', 'tenant_id');

        $result = [];

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $result[$tenantId] = [
                'students' => (int) ($students[$tenantId] ?? 0),
                'courses' => (int) ($courses[$tenantId] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * The first owner-role user's name per tenant, for the institutes list column —
     * one grouped query, not N+1. A tenant with no owner yet is simply absent from the
     * returned array.
     *
     * @return array<int, string>
     */
    public function ownerNamesByTenant(): array
    {
        // Raw query for a platform-wide cross-tenant report; grouped and reduced to one
        // name per tenant_id below, never leaking another tenant's user rows.
        $owners = DB::table('users')
            ->select('tenant_id', 'name', 'id')
            ->where('role', UserRole::Owner->value)
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($owners as $owner) {
            $result[$owner->tenant_id] ??= $owner->name;
        }

        return $result;
    }
}

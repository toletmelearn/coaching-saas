<?php

namespace Database\Seeders;

use App\Enums\DomainType;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['name' => 'Demo Institute'],
            ['status' => TenantStatus::Active],
        );

        TenantDomain::query()->firstOrCreate(
            ['domain' => 'demo.coaching.test'],
            [
                'tenant_id' => $tenant->id,
                'type' => DomainType::Subdomain,
                'is_primary' => true,
                'verified_at' => now(),
            ],
        );

        // Local dev/demo credentials — see README "Local credentials".
        app(TenantContext::class)->runAs($tenant, function () {
            $owner = User::query()->firstOrCreate(
                ['email' => 'owner@demo.coaching.test'],
                ['name' => 'Demo Owner', 'password' => Hash::make('password')],
            );
            $owner->forceFill(['role' => 'owner', 'status' => 'active', 'must_change_password' => false])->save();
        });
    }
}

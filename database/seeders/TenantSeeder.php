<?php

namespace Database\Seeders;

use App\Enums\DomainType;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Database\Seeder;

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
    }
}

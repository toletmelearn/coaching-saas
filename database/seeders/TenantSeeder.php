<?php

namespace Database\Seeders;

use App\Enums\DomainType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
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

        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'Skipping demo user seeding: local-only credentials are never seeded outside local/testing environments.'
            );

            return;
        }

        // Local dev/demo credentials — see README "Local credentials". Never seeded
        // outside local/testing (guarded above).
        app(TenantContext::class)->runAs($tenant, function () {
            $owner = User::query()->firstOrCreate(
                ['email' => 'owner@demo.coaching.test'],
                ['name' => 'Demo Owner', 'password' => Hash::make('password')],
            );
            $owner->forceFill(['role' => UserRole::Owner, 'status' => UserStatus::Active, 'must_change_password' => false])->save();

            $student = User::query()->firstOrCreate(
                ['email' => 'student@demo.coaching.test'],
                ['name' => 'Demo Student', 'password' => Hash::make('password')],
            );
            $student->forceFill(['role' => UserRole::Student, 'status' => UserStatus::Active, 'must_change_password' => false])->save();
        });
    }
}

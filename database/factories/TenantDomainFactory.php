<?php

namespace Database\Factories;

use App\Enums\DomainType;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => $this->faker->unique()->domainWord().'.coaching.test',
            'type' => DomainType::Subdomain,
            'is_primary' => true,
            'verified_at' => now(),
        ];
    }
}

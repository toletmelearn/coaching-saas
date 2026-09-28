<?php

namespace Tests\Fixtures\Factories;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Fixtures\Models\TenancyTestChild;
use Tests\Fixtures\Models\TenancyTestParent;

class TenancyTestChildFactory extends Factory
{
    protected $model = TenancyTestChild::class;

    public function definition(): array
    {
        // Factories bypass model hooks, so we must set tenant_id explicitly from context
        $context = app(TenantContext::class);

        return [
            'tenant_id' => $context->has() ? $context->id() : null,
            'parent_id' => TenancyTestParent::factory(),
            'name' => $this->faker->words(2, true),
        ];
    }
}

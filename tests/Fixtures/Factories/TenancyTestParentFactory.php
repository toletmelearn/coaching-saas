<?php

namespace Tests\Fixtures\Factories;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Tests\Fixtures\Models\TenancyTestParent;

class TenancyTestParentFactory extends Factory
{
    protected $model = TenancyTestParent::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        // Factories bypass model hooks, so we must set tenant_id explicitly from context
        $context = app(TenantContext::class);

        return [
            'tenant_id' => $context->has() ? $context->id() : null,
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}

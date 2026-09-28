<?php

namespace Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Fixtures\Models\TenancyTestChild;
use Tests\Fixtures\Models\TenancyTestParent;

class TenancyTestChildFactory extends Factory
{
    protected $model = TenancyTestChild::class;

    public function definition(): array
    {
        return [
            'parent_id' => TenancyTestParent::factory(),
            'name' => $this->faker->words(2, true),
        ];
    }
}

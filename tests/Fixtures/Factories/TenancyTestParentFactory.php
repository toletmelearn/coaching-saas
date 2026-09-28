<?php

namespace Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Tests\Fixtures\Models\TenancyTestParent;

class TenancyTestParentFactory extends Factory
{
    protected $model = TenancyTestParent::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}

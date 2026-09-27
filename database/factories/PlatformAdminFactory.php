<?php

namespace Database\Factories;

use App\Enums\PlatformAdminStatus;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class PlatformAdminFactory extends Factory
{
    protected $model = PlatformAdmin::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'status' => PlatformAdminStatus::Active,
            'last_login_at' => null,
        ];
    }
}

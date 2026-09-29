<?php

namespace Database\Factories;

use App\Enums\DemoRequestStatus;
use App\Models\DemoRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoRequest>
 */
class DemoRequestFactory extends Factory
{
    protected $model = DemoRequest::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'phone' => '9'.$this->faker->numerify('#########'),
            'email' => $this->faker->safeEmail(),
            'institute_name' => $this->faker->company(),
            'city' => $this->faker->city(),
            'message' => $this->faker->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (DemoRequest $demoRequest) {
            $demoRequest->forceFill([
                'status' => $demoRequest->status ?? DemoRequestStatus::New,
            ]);
        });
    }

    public function contacted(): static
    {
        return $this->afterMaking(fn (DemoRequest $demoRequest) => $demoRequest->forceFill([
            'status' => DemoRequestStatus::Contacted,
            'contacted_at' => now(),
        ]));
    }
}

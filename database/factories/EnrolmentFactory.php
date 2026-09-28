<?php

namespace Database\Factories;

use App\Models\Enrolment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrolment>
 */
class EnrolmentFactory extends Factory
{
    protected $model = Enrolment::class;

    public function definition(): array
    {
        return [
            'starts_at' => now()->subDay(),
            'ends_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Enrolment $enrolment) {
            $enrolment->forceFill([
                'status' => $enrolment->status ?? 'active',
            ]);
        });
    }

    public function active(): static
    {
        return $this->afterMaking(fn (Enrolment $enrolment) => $enrolment->forceFill(['status' => 'active']));
    }

    public function revoked(): static
    {
        return $this->afterMaking(fn (Enrolment $enrolment) => $enrolment->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]));
    }
}

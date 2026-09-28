<?php

namespace Database\Factories;

use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(4),
            'description' => fake()->paragraph(),
            'is_free_preview' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Lesson $lesson) {
            $lesson->forceFill([
                'status' => $lesson->status ?? 'draft',
            ]);
        });
    }

    public function draft(): static
    {
        return $this->afterMaking(fn (Lesson $lesson) => $lesson->forceFill(['status' => 'draft']));
    }

    public function published(): static
    {
        return $this->afterMaking(fn (Lesson $lesson) => $lesson->forceFill([
            'status' => 'published',
            'published_at' => now(),
        ]));
    }
}

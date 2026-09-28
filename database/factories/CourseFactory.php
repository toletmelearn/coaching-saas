<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => $title,
            'description' => fake()->paragraph(),
            'class_level' => (string) fake()->numberBetween(6, 12),
            'subject' => fake()->randomElement(['Physics', 'Chemistry', 'Maths', 'Biology']),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Course $course) {
            $course->forceFill([
                'status' => $course->status ?? 'draft',
            ]);
        });
    }

    public function draft(): static
    {
        return $this->afterMaking(fn (Course $course) => $course->forceFill(['status' => 'draft']));
    }

    public function published(): static
    {
        return $this->afterMaking(fn (Course $course) => $course->forceFill([
            'status' => 'published',
            'published_at' => now(),
        ]));
    }

    public function archived(): static
    {
        return $this->afterMaking(fn (Course $course) => $course->forceFill(['status' => 'archived']));
    }
}

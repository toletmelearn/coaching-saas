<?php

namespace Database\Factories;

use App\Models\LessonProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonProgress>
 */
class LessonProgressFactory extends Factory
{
    protected $model = LessonProgress::class;

    public function definition(): array
    {
        return [];
    }

    /**
     * Auto-completed (video watched to threshold) — completed_manually stays false.
     */
    public function completed(): static
    {
        return $this->afterMaking(fn (LessonProgress $progress) => $progress->forceFill([
            'completed_at' => $progress->completed_at ?? now(),
            'completed_manually' => false,
        ]));
    }

    /**
     * Student tapped "Mark as complete".
     */
    public function manuallyCompleted(): static
    {
        return $this->afterMaking(fn (LessonProgress $progress) => $progress->forceFill([
            'completed_at' => $progress->completed_at ?? now(),
            'completed_manually' => true,
        ]));
    }

    public function watched(int $seconds, ?int $durationSeconds = null): static
    {
        return $this->afterMaking(fn (LessonProgress $progress) => $progress->forceFill([
            'watched_seconds' => $seconds,
            'duration_seconds' => $durationSeconds ?? $progress->duration_seconds,
        ]));
    }

    public function atPosition(int $seconds, ?int $durationSeconds = null): static
    {
        return $this->afterMaking(fn (LessonProgress $progress) => $progress->forceFill([
            'last_position_seconds' => $seconds,
            'duration_seconds' => $durationSeconds ?? $progress->duration_seconds,
        ]));
    }

    public function inactiveSince(int $days): static
    {
        return $this->afterMaking(fn (LessonProgress $progress) => $progress->forceFill([
            'last_activity_at' => now()->subDays($days),
        ]));
    }
}

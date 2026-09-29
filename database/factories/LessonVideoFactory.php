<?php

namespace Database\Factories;

use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LessonVideo>
 */
class LessonVideoFactory extends Factory
{
    protected $model = LessonVideo::class;

    public function definition(): array
    {
        return [
            'original_filename' => 'lecture.mp4',
            'size_bytes' => 10_000_000,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (LessonVideo $video) {
            $video->forceFill([
                'provider' => $video->provider ?? 'fake',
                'status' => $video->status ?? VideoStatus::AwaitingUpload,
            ]);
        });
    }

    public function bunny(): static
    {
        return $this->afterMaking(fn (LessonVideo $video) => $video->forceFill(['provider' => 'bunny']));
    }

    public function ready(): static
    {
        return $this->afterMaking(fn (LessonVideo $video) => $video->forceFill([
            'status' => VideoStatus::Ready,
            'provider_video_id' => $video->provider_video_id ?? (string) Str::uuid(),
            'duration_seconds' => $video->duration_seconds ?? 120,
        ]));
    }
}

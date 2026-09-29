<?php

namespace Database\Factories;

use App\Models\LessonAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<LessonAttachment>
 */
class LessonAttachmentFactory extends Factory
{
    protected $model = LessonAttachment::class;

    public function definition(): array
    {
        return [
            'original_name' => 'notes.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (LessonAttachment $attachment) {
            $attachment->forceFill([
                'disk' => $attachment->disk ?? 'local',
                'path' => $attachment->path ?? sprintf('tenants/pending/%s.pdf', Str::random(20)),
            ]);
        })->afterCreating(function (LessonAttachment $attachment) {
            $path = sprintf('tenants/%d/lessons/%d/%s.pdf', $attachment->tenant_id, $attachment->lesson_id, Str::random(20));
            $attachment->forceFill(['path' => $path])->save();

            Storage::disk($attachment->disk)->put($attachment->path, '%PDF-1.4 fake attachment content');
        });
    }
}

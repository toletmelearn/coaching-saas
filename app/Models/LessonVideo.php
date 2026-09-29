<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Traits\BelongsToTenant;
use Database\Factories\LessonVideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @use HasFactory<LessonVideoFactory>
 */
class LessonVideo extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['lesson_id', 'original_filename', 'size_bytes'];

    protected $attributes = [
        'provider' => 'fake',
        'status' => 'awaiting_upload',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
        ];
    }

    protected static function newFactory(): LessonVideoFactory
    {
        return LessonVideoFactory::new();
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Physical path on the `local` (private) disk for the `fake` driver only — the bunny
     * driver never stores bytes locally. Keyed by the video's own id, never the
     * teacher-supplied original filename, so a crafted filename can never influence the
     * storage path.
     */
    public function getStoragePathAttribute(): string
    {
        return "tenants/{$this->tenant_id}/videos/{$this->id}.mp4";
    }
}

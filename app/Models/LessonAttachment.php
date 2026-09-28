<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\LessonAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @use HasFactory<LessonAttachmentFactory>
 */
class LessonAttachment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['lesson_id', 'original_name', 'mime_type', 'size_bytes', 'position'];

    protected static function newFactory(): LessonAttachmentFactory
    {
        return LessonAttachmentFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (LessonAttachment $attachment) {
            if ($attachment->getAttribute('position') === null) {
                $max = static::where('lesson_id', $attachment->lesson_id)->max('position');
                $attachment->position = ($max ?? 0) + 1;
            }
        });
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}

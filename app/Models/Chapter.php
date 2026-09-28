<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\ChapterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @use HasFactory<ChapterFactory>
 */
class Chapter extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['title', 'position'];

    protected static function newFactory(): ChapterFactory
    {
        return ChapterFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Chapter $chapter) {
            if ($chapter->getAttribute('position') === null) {
                $max = static::where('course_id', $chapter->course_id)->max('position');
                $chapter->position = ($max ?? 0) + 1;
            }
        });
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position');
    }
}

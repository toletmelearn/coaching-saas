<?php

namespace App\Models;

use App\Exceptions\LessonProgressCourseMismatchException;
use App\Traits\BelongsToTenant;
use Database\Factories\LessonProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @use HasFactory<LessonProgressFactory>
 */
class LessonProgress extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Laravel's pluraliser treats "progress" as uncountable ("lesson_progresss" would be
     * wrong), so the table name is set explicitly rather than relying on convention.
     */
    protected $table = 'lesson_progress';

    protected $fillable = ['lesson_id', 'course_id', 'user_id'];

    protected $attributes = [
        'watched_seconds' => 0,
        'last_position_seconds' => 0,
        'completed_manually' => false,
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'completed_manually' => 'boolean',
            'last_heartbeat_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LessonProgressFactory
    {
        return LessonProgressFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (LessonProgress $progress) {
            $lesson = Lesson::withoutGlobalScopes()
                ->where('tenant_id', $progress->tenant_id)
                ->where('id', $progress->lesson_id)
                ->first();

            if ($lesson !== null && (int) $lesson->course_id !== (int) $progress->course_id) {
                throw new LessonProgressCourseMismatchException;
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

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}

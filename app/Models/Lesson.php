<?php

namespace App\Models;

use App\Enums\LessonStatus;
use App\Exceptions\LessonCourseMismatchException;
use App\Traits\BelongsToTenant;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @use HasFactory<LessonFactory>
 */
class Lesson extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'chapter_id',
        'course_id',
        'title',
        'description',
        'position',
        'board_tag',
        'is_free_preview',
        'youtube_video_id',
    ];

    protected $attributes = [
        'status' => 'draft',
        'is_free_preview' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'published_at' => 'datetime',
            'is_free_preview' => 'boolean',
        ];
    }

    protected static function newFactory(): LessonFactory
    {
        return LessonFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (Lesson $lesson) {
            $chapter = Chapter::withoutGlobalScopes()
                ->where('tenant_id', $lesson->tenant_id)
                ->where('id', $lesson->chapter_id)
                ->first();

            if ($chapter !== null && (int) $chapter->course_id !== (int) $lesson->course_id) {
                throw new LessonCourseMismatchException;
            }
        });

        static::creating(function (Lesson $lesson) {
            if ($lesson->getAttribute('position') === null) {
                $max = static::where('chapter_id', $lesson->chapter_id)->max('position');
                $lesson->position = ($max ?? 0) + 1;
            }
        });
    }

    /**
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<LessonAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class)->orderBy('position');
    }

    /**
     * Every published lesson in the course, in chapter/lesson position order — the single
     * definition of "lesson order" shared by lesson navigation (previous/next) and "first
     * lesson to continue to" on the student dashboard.
     *
     * @return Collection<int, self>
     */
    public static function orderedPublishedForCourse(Course $course): Collection
    {
        return static::where('lessons.course_id', $course->id)
            ->where('lessons.status', LessonStatus::Published)
            ->join('chapters', 'lessons.chapter_id', '=', 'chapters.id')
            ->orderBy('chapters.position')
            ->orderBy('lessons.position')
            ->select('lessons.*')
            ->get();
    }
}

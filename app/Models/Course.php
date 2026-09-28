<?php

namespace App\Models;

use App\Enums\CourseStatus;
use App\Support\TenantContext;
use App\Traits\BelongsToTenant;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @use HasFactory<CourseFactory>
 */
class Course extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['title', 'slug', 'description', 'class_level', 'subject'];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CourseFactory
    {
        return CourseFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Course $course) {
            if (blank($course->slug)) {
                $course->slug = static::uniqueSlug(Str::slug($course->title));
            }
        });
    }

    public static function uniqueSlug(string $base): string
    {
        $context = app(TenantContext::class);
        $slug = $base;
        $suffix = 2;

        while (
            static::withoutGlobalScopes()
                ->where('tenant_id', $context->id())
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return HasMany<Chapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('position');
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position');
    }

    /**
     * @return HasMany<Enrolment, $this>
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }
}

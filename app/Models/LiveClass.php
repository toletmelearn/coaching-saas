<?php

namespace App\Models;

use App\Enums\LiveClassStatus;
use App\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scheduled Jitsi session for one course (optionally tied to one lesson).
 *
 * Server-owned columns — tenant_id, status, jitsi_room_name, created_by — are
 * deliberately excluded from $fillable and written through forceFill() only,
 * so a forged schedule request cannot pick its own room name, jump straight to
 * "ended", or name a creator in another tenant.
 */
class LiveClass extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_id', 'lesson_id', 'title', 'description', 'starts_at', 'ends_at', 'meeting_url'];

    protected $attributes = [
        'status' => 'scheduled',
    ];

    /** Default length of a class that was scheduled without an explicit end. */
    public const DEFAULT_DURATION_MINUTES = 90;

    /** How long before starts_at a student may open the room. */
    public const JOIN_WINDOW_MINUTES = 15;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => LiveClassStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // The room name is generated, never supplied: 32 random letters (A-Za-z,
        // ~181 bits) plus an 8-character [a-f] suffix derived from the tenant id.
        // Letters only — deliberately digit-free — so no numeric id (class, course,
        // tenant) can ever appear inside a room name or be read back out of one
        // (LiveClassSchedulingTest's unpredictability contract), while the suffix
        // still gives every tenant visually distinct rooms. Runs after
        // BelongsToTenant's own creating hook (registered during bootTraits),
        // which is what guarantees tenant_id is already set here.
        static::creating(function (LiveClass $class) {
            if ($class->jitsi_room_name) {
                return;
            }

            $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
            $random = '';

            for ($i = 0; $i < 32; $i++) {
                $random .= $alphabet[random_int(0, 51)];
            }

            $digest = hash('sha256', (string) $class->tenant_id, true);
            $suffix = '';

            for ($i = 0; $i < 8; $i++) {
                $suffix .= chr(ord('a') + (ord($digest[$i]) % 6));
            }

            $class->jitsi_room_name = $random.'-'.$suffix;
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
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<LiveClassAttendance, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(LiveClassAttendance::class);
    }

    /**
     * When the class stops counting as live: ends_at when given, otherwise
     * starts_at + 90 minutes (docs/specs/phase-12-live-classes.md).
     */
    public function effectiveEndsAt(): CarbonInterface
    {
        return $this->ends_at ?? $this->starts_at->copy()->addMinutes(self::DEFAULT_DURATION_MINUTES);
    }
}

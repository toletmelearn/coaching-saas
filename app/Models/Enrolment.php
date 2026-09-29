<?php

namespace App\Models;

use App\Enums\EnrolmentStatus;
use App\Traits\BelongsToTenant;
use Database\Factories\EnrolmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @use HasFactory<EnrolmentFactory>
 */
class Enrolment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['course_id', 'user_id', 'starts_at', 'ends_at', 'payment_note'];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrolmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EnrolmentFactory
    {
        return EnrolmentFactory::new();
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

    public function revoke(?int $revokedBy = null): void
    {
        $this->forceFill([
            'status' => EnrolmentStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by' => $revokedBy,
        ])->save();
    }

    public function isValidNow(): bool
    {
        if ($this->status !== EnrolmentStatus::Active) {
            return false;
        }

        $now = now();

        if ($this->starts_at->gt($now)) {
            return false;
        }

        if ($this->ends_at !== null && $now->gte($this->ends_at)) {
            return false;
        }

        return true;
    }
}

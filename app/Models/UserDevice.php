<?php

namespace App\Models;

use App\Enums\DeviceRevocationReason;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    use BelongsToTenant;

    /**
     * device_id and user_id are the identity fields a caller must supply to create a row
     * (mirrors LessonProgress's fillable lesson_id/course_id/user_id) — every other
     * column (tenant_id, revoked_*, notified_at, first/last seen) is guarded and only
     * ever set via forceFill() from DeviceRegistrar, never from raw request input.
     */
    protected $fillable = ['user_id', 'device_id', 'label', 'user_agent'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'revoked_reason' => DeviceRevocationReason::class,
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserDevice $device) {
            $device->first_seen_at ??= now();
            $device->last_seen_at ??= now();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}

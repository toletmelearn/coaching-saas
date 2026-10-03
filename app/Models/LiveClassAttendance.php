<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stay of one user in one live class (Phase 12).
 *
 * Every column except id/created_at/updated_at is guarded: the heartbeat body
 * is ignored outright (LiveClassController::heartbeat reads nothing from the
 * request), so a forger cannot backdate joined_at, freeze last_seen_at, or
 * inflate duration_seconds. Written only via forceFill() by the recorder.
 */
class LiveClassAttendance extends Model
{
    use BelongsToTenant;

    /** The table name is deliberately uncountable ("attendance" = a mass noun). */
    protected $table = 'live_class_attendance';

    protected $guarded = [
        'id',
        'tenant_id',
        'live_class_id',
        'user_id',
        'joined_at',
        'last_seen_at',
        'left_at',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'left_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LiveClass, $this>
     */
    public function liveClass(): BelongsTo
    {
        return $this->belongsTo(LiveClass::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

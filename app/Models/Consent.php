<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One granted DPDP consent for one purpose, stamped with the notice version it
 * was collected under, the collection method and who recorded it.
 *
 * Withdrawal never deletes the row it cancels: `withdrawn_at`/`withdrawn_reason`
 * are set alongside the untouched grant so both sides of the history stay
 * readable. `purpose`, `method` and `user_id` are legitimate request data and
 * therefore fillable; tenancy, guardian, recorder, grant and withdrawal columns
 * are deliberately guarded (proved by ConsentSchemaTest's positive control).
 */
#[Fillable(['user_id', 'purpose', 'method'])]
class Consent extends Model
{
    use BelongsToTenant;

    /** The notice version stamped on every consent recorded by this codebase. */
    public const NOTICE_VERSION = 'v1.0-2026-10-04';

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'notice_version' => self::NOTICE_VERSION,
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The tenant user (owner/staff) who recorded the consent.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

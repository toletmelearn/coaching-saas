<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The DPDP audit trail: one row per significant consent action (today the only
 * action is `student_erased`). `metadata` carries a JSON snapshot of the rows
 * erasure removed — enrolments, live-class attendance and payments keyed by
 * their field values — so the financial and participation facts survive the
 * rows themselves without the row carrying personal data.
 */
#[Fillable(['action', 'metadata'])]
class ConsentAuditLog extends Model
{
    use BelongsToTenant;

    /** The audit action written when a student is erased. */
    public const ACTION_STUDENT_ERASED = 'student_erased';

    /** A payment screenshot could not be deleted after erasure committed. */
    public const ACTION_SCREENSHOT_DELETE_FAILED = 'screenshot_delete_failed';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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
     * The tenant user (owner/staff) who performed the action.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

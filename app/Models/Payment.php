<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's proof-of-payment for one enrolment, reviewed by hand.
 *
 * Every server-derived column (tenant_id, enrolment_id, amount_paise, status,
 * submitted_at, reviewed_at, reviewed_by, rejection_reason, screenshot_path) is
 * deliberately excluded from $fillable: only the two values a client is ever
 * allowed to supply are mass-assignable. Everything else is written through
 * forceFill() by the controller, so a forged request body cannot set its own
 * price, mark itself approved, or point at another tenant's file.
 */
class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['upi_reference', 'screenshot_path'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_paise' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrolment, $this>
     */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    /**
     * The tenant user who approved or rejected this payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

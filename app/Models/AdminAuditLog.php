<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per privileged platform-admin action (Phase 13).
 *
 * Central model — no BelongsToTenant, no tenant_id (see the migration's
 * docblock). Append-only: updated_at is disabled, and nothing in the app
 * updates or deletes audit rows.
 *
 * Action vocabulary (docs/specs/phase-13-admin-panel.md): the five required
 * actions — login, create_tenant, reset_password, update_setting, impersonate —
 * plus backup_run / backup_delete / clear_cache / optimize for the operational
 * buttons on /admin/backups and /admin/settings/system. Two-factor actions:
 * two_factor_failed, two_factor_locked, two_factor_enrolled, two_factor_replaced.
 * Impersonation actions: impersonate, impersonation_blocked, impersonation_exit,
 * impersonation_expired. Rows never carry a secret, a code or a payload.
 *
 * Retention: the two-factor lock counts two_factor_failed rows for the last hour, so a
 * retention job must keep at least the last hour of those rows (see SECURITY.md). For update_setting the
 * target_type carries the exact subject ("env:SESSION_LIFETIME",
 * "setting:jitsi_app_secret", "health_fix:APP_DEBUG") so the trail says what
 * actually changed without a schema deviation from the spec.
 *
 * @property int $id
 * @property int $admin_id
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'action', 'target_type', 'target_id', 'ip_address'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Append one audit row. Never throws away context: every caller passes the
     * acting platform admin id (the impersonation flow reads it from the signed
     * URL, which a client cannot forge).
     *
     * @param  string|null  $targetType  entity type, or a typed subject like "env:SESSION_LIFETIME"
     */
    public static function record(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?int $adminId = null,
        ?string $ipAddress = null,
    ): self {
        return self::query()->create([
            'admin_id' => (int) $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => $ipAddress,
        ]);
    }

    public function scopeFilterByAction(Builder $query, ?string $action): Builder
    {
        return $action === null || $action === ''
            ? $query
            : $query->where('action', $action);
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }
}

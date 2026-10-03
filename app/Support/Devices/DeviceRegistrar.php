<?php

namespace App\Support\Devices;

use App\Enums\DeviceRevocationReason;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class DeviceRegistrar
{
    public const COOKIE_NAME = 'device_id';

    public const COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(
        private readonly DeviceLabelParser $labelParser,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mintDeviceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Upsert the (tenant, user, device) row for a login/registration event, then evict
     * the oldest active device(s) if the student is now over their tenant's limit.
     * Concurrency-safe: row locks on the student's device rows inside one transaction.
     */
    public function register(User $user, string $deviceId, ?string $userAgent): UserDevice
    {
        return DB::transaction(function () use ($user, $deviceId, $userAgent) {
            $device = UserDevice::where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->lockForUpdate()
                ->first();

            $isNew = $device === null;

            if ($device === null) {
                $device = new UserDevice([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                ]);
            }

            $device->forceFill([
                'label' => $this->labelParser->parse($userAgent),
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'last_seen_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
            ]);

            if ($isNew) {
                $device->forceFill(['first_seen_at' => now()]);
            }

            $device->save();

            $this->enforceLimit($user, $device);

            return $device->refresh();
        });
    }

    /**
     * Revokes the oldest active device(s) beyond the tenant's limit — never the device
     * that was just registered. Called from within register()'s own transaction, so the
     * row locks already held there cover this too.
     */
    private function enforceLimit(User $user, UserDevice $current): void
    {
        $limit = max(1, (int) $this->tenantContext->get()->max_devices_per_student);

        // last_seen_at is a second-precision timestamp with no index, so four logins
        // can land in the same wall-clock second (CI runs the whole suite in ~30 s)
        // and MySQL then returns the tied rows in unspecified order — "revoke the
        // oldest" silently becomes "revoke an arbitrary one". id is auto-increment and
        // rows are created in order, so it is a stable, correct "oldest" tie-break.
        $active = UserDevice::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->orderBy('last_seen_at')
            ->orderBy('id')
            ->get();

        $toRevoke = $active->count() - $limit;

        if ($toRevoke <= 0) {
            return;
        }

        $active->reject(fn (UserDevice $device) => $device->id === $current->id)
            ->take($toRevoke)
            ->each(fn (UserDevice $device) => $this->revokeOne($device, DeviceRevocationReason::Replaced));
    }

    public function revokeOne(UserDevice $device, DeviceRevocationReason $reason): void
    {
        $device->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();
    }

    public function revokeAll(User $user, DeviceRevocationReason $reason): void
    {
        UserDevice::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (UserDevice $device) => $this->revokeOne($device, $reason));
    }

    public function revokeAllExcept(User $user, string $currentDeviceId, DeviceRevocationReason $reason): void
    {
        UserDevice::where('user_id', $user->id)
            ->where('device_id', '!=', $currentDeviceId)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (UserDevice $device) => $this->revokeOne($device, $reason));
    }
}

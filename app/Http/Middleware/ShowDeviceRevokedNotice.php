<?php

namespace App\Http\Middleware;

use App\Enums\DeviceRevocationReason;
use App\Models\UserDevice;
use App\Support\Devices\DeviceRegistrar;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied only to the GET /login route (never the login controller itself). A device
 * that is no longer authenticated (its session is gone) but still holds its device_id
 * cookie sees the "signed out because your account was opened on another device"
 * notice once, the first time it lands back on the login page — then notified_at is
 * set so it never shows again for that device. Only for the reasons a student would
 * actually want explained this way: replaced/owner/password_* — never "logout" (a
 * deliberate, expected sign-out) or "disabled" (a different, more specific situation).
 */
class ShowDeviceRevokedNotice
{
    private const NOTICE_REASONS = [
        DeviceRevocationReason::Replaced,
        DeviceRevocationReason::Owner,
        DeviceRevocationReason::PasswordChanged,
        DeviceRevocationReason::PasswordReset,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->cookie(DeviceRegistrar::COOKIE_NAME);

        if ($deviceId !== null) {
            $device = UserDevice::where('device_id', $deviceId)
                ->whereNotNull('revoked_at')
                ->whereNull('notified_at')
                ->whereIn('revoked_reason', self::NOTICE_REASONS)
                ->first();

            if ($device !== null) {
                $device->forceFill(['notified_at' => now()])->save();

                // session()->now(), not flash(): the DB's notified_at column is the
                // source of truth for "already shown", not Laravel's flash-survives-
                // one-more-request semantics — a page reload a moment later must not
                // show the notice again just because a flash value happened to still
                // be alive for "the next request" too.
                $request->session()->now('status', __('auth.device_revoked'));
            }
        }

        return $next($request);
    }
}

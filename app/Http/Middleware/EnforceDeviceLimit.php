<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\UserDevice;
use App\Support\Devices\DeviceRegistrar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered as the `device.limit` route middleware alias. A no-op for a guest or for
 * owner/staff (checked before any device_id/UserDevice query runs, so neither costs a
 * query). For an authenticated student: resolves the device by cookie, registering it
 * on the fly if the cookie/row is missing (a session that pre-dates this phase), logs
 * the student out if their device has been revoked, and otherwise throttles the
 * last_seen_at write to once per 60 seconds via a single atomic conditional UPDATE —
 * never a read-then-write. At most one SELECT and one UPDATE against user_devices per
 * request (see tests/Feature/Devices/DeviceLimitEnforcementTest.php).
 */
class EnforceDeviceLimit
{
    public function __construct(private readonly DeviceRegistrar $registrar) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('tenant')->user();

        if ($user === null || $user->role !== UserRole::Student) {
            return $next($request);
        }

        $deviceId = $request->cookie(DeviceRegistrar::COOKIE_NAME);

        if ($deviceId === null) {
            $deviceId = $this->registrar->mintDeviceId();
            $this->registrar->register($user, $deviceId, $request->userAgent());
            Cookie::queue(DeviceRegistrar::COOKIE_NAME, $deviceId, DeviceRegistrar::COOKIE_MINUTES);

            return $next($request);
        }

        $device = UserDevice::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();

        if ($device === null) {
            $this->registrar->register($user, $deviceId, $request->userAgent());

            return $next($request);
        }

        if ($device->revoked_at !== null) {
            return $this->signOutRevokedDevice($request, $device);
        }

        UserDevice::where('id', $device->id)
            ->where('last_seen_at', '<', now()->subSeconds(60))
            ->update(['last_seen_at' => now()]);

        return $next($request);
    }

    private function signOutRevokedDevice(Request $request, UserDevice $device): Response
    {
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($device->notified_at === null) {
            $device->forceFill(['notified_at' => now()])->save();
        }

        if ($request->expectsJson()) {
            return response()->json(['code' => 'device_revoked'], 401);
        }

        return redirect('/login')->with('status', __('auth.device_revoked'));
    }
}

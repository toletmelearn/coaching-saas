<?php

namespace App\Http\Controllers\Auth;

use App\Enums\DeviceRevocationReason;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use App\Support\Devices\DeviceRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantLogoutController extends Controller
{
    public function __construct(private readonly DeviceRegistrar $deviceRegistrar) {}

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::guard('tenant')->user();
        $deviceId = $request->cookie(DeviceRegistrar::COOKIE_NAME);

        // Frees the device slot rather than leaving it counted against the limit — a
        // student logging back in on this same device reactivates the same row.
        if ($user !== null && $user->role === UserRole::Student && $deviceId !== null) {
            $device = UserDevice::where('user_id', $user->id)->where('device_id', $deviceId)->first();

            if ($device !== null && $device->revoked_at === null) {
                $this->deviceRegistrar->revokeOne($device, DeviceRevocationReason::Logout);
            }
        }

        Auth::guard('tenant')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}

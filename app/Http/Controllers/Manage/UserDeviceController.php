<?php

namespace App\Http\Controllers\Manage;

use App\Enums\DeviceRevocationReason;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\Devices\DeviceRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserDeviceController extends Controller
{
    public function __construct(private readonly DeviceRegistrar $registrar) {}

    public function index(User $user): View
    {
        Gate::authorize('viewDevices', $user);

        $devices = $user->devices()->orderByDesc('last_seen_at')->get();

        $recentReplacements = $user->devices()
            ->where('revoked_reason', DeviceRevocationReason::Replaced)
            ->where('revoked_at', '>=', now()->subDays(7))
            ->count();

        return view('users.devices', [
            'targetUser' => $user,
            'devices' => $devices,
            'switchingOften' => $recentReplacements >= (int) config('coaching.device_switch_flag', 5),
        ]);
    }

    public function signOutOne(User $user, UserDevice $device): RedirectResponse
    {
        Gate::authorize('manageDevices', $user);

        if ($device->revoked_at === null) {
            $this->registrar->revokeOne($device, DeviceRevocationReason::Owner);
        }

        return redirect("/users/{$user->id}/devices");
    }

    public function signOutAll(User $user): RedirectResponse
    {
        Gate::authorize('manageDevices', $user);

        $this->registrar->revokeAll($user, DeviceRevocationReason::Owner);

        return redirect("/users/{$user->id}/devices");
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Enums\DeviceRevocationReason;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Support\Devices\DeviceRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ChangePasswordController extends Controller
{
    public function __construct(private readonly DeviceRegistrar $deviceRegistrar) {}

    public function show(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = Auth::guard('tenant')->user();

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        $currentDeviceId = $request->cookie(DeviceRegistrar::COOKIE_NAME);

        if ($user->role === UserRole::Student && $currentDeviceId !== null) {
            $this->deviceRegistrar->revokeAllExcept($user, $currentDeviceId, DeviceRevocationReason::PasswordChanged);
        }

        return redirect('/dashboard');
    }
}

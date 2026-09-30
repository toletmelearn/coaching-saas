<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Devices\DeviceRegistrar;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Fires on every Illuminate\Auth\Events\Login for the tenant guard — both a fresh
 * TenantLoginController::store() login and Laravel's own remember-me cookie restore
 * (SessionGuard::user() fires the same event when it resolves the user from the
 * recaller token), so a session that survives via "remember me" reuses its device row
 * exactly like a same-device re-login would. Deliberately never touches the login
 * controller itself — registration is entirely decoupled via this listener.
 */
class RegisterUserDevice
{
    public function __construct(
        private readonly DeviceRegistrar $registrar,
        private readonly Request $request,
    ) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'tenant') {
            return;
        }

        /** @var User $user */
        $user = $event->user;

        if ($user->role !== UserRole::Student) {
            return;
        }

        $deviceId = $this->request->cookie(DeviceRegistrar::COOKIE_NAME);
        $isNewCookie = $deviceId === null;

        if ($isNewCookie) {
            $deviceId = $this->registrar->mintDeviceId();
        }

        $this->registrar->register($user, $deviceId, $this->request->userAgent());

        if ($isNewCookie) {
            Cookie::queue(DeviceRegistrar::COOKIE_NAME, $deviceId, DeviceRegistrar::COOKIE_MINUTES);
        }
    }
}

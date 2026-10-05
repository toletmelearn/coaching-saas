<?php

namespace App\Support\Auth;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Accepts a TOTP step or consumes a recovery code for one platform admin. Both writes re-read the
 * admin row under lockForUpdate() inside a transaction, so two requests that interleave on the same
 * admin cannot both accept one step or one recovery code, however stale their copy of the model.
 */
class TwoFactorVerifier
{
    public function acceptTotp(int $adminId, string $code, int $timestamp): bool
    {
        return DB::transaction(function () use ($adminId, $code, $timestamp): bool {
            $admin = PlatformAdmin::query()->whereKey($adminId)->lockForUpdate()->first();

            if ($admin === null || $admin->totp_secret === null) {
                return false;
            }

            $step = Totp::matchStep($admin->totp_secret, $code, $timestamp);

            if ($step === null || ($admin->totp_last_step !== null && $step <= $admin->totp_last_step)) {
                return false;
            }

            $admin->forceFill(['totp_last_step' => $step])->save();

            return true;
        });
    }

    public function consumeRecoveryCode(int $adminId, string $input): bool
    {
        return DB::transaction(function () use ($adminId, $input): bool {
            $admin = PlatformAdmin::query()->whereKey($adminId)->lockForUpdate()->first();

            if ($admin === null) {
                return false;
            }

            $needle = Str::lower(trim($input));
            $remaining = $admin->recovery_codes ?? [];

            foreach ($remaining as $index => $hash) {
                if (Hash::check($needle, $hash)) {
                    unset($remaining[$index]);
                    $admin->forceFill(['recovery_codes' => array_values($remaining)])->save();

                    return true;
                }
            }

            return false;
        });
    }
}

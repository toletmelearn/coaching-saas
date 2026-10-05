<?php

use App\Models\PlatformAdmin;
use App\Support\Auth\Totp;
use App\Support\Auth\TwoFactorVerifier;
use Illuminate\Support\Facades\Hash;

/**
 * Two requests that interleave on the same admin must not both accept one TOTP step or one
 * recovery code. The verifier re-reads the row inside a transaction with lockForUpdate(), so a stale
 * copy of the admin taken before the first acceptance is still refused.
 */
const P16I_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
const P16I_RECOVERY = 'interleave-ok';

test('a TOTP step accepted by one request is refused to an interleaved request holding a stale copy (positive control: the first acceptance succeeds)', function () {
    expect(class_exists(TwoFactorVerifier::class))->toBeTrue();

    $admin = PlatformAdmin::factory()->create();
    $admin->forceFill(['totp_secret' => P16I_SECRET, 'totp_enabled_at' => now(), 'totp_last_step' => null])->save();
    $stale = PlatformAdmin::find($admin->id);
    $code = Totp::code(P16I_SECRET, time());

    $verifier = app(TwoFactorVerifier::class);

    expect($verifier->acceptTotp($admin->id, $code, time()))->toBeTrue();
    expect($verifier->acceptTotp($stale->id, $code, time()))->toBeFalse();
});

test('a recovery code consumed by one request cannot be consumed by an interleaved request (positive control: the first use succeeds)', function () {
    expect(class_exists(TwoFactorVerifier::class))->toBeTrue();

    $admin = PlatformAdmin::factory()->create();
    $admin->forceFill(['recovery_codes' => [Hash::make(P16I_RECOVERY)]])->save();

    $verifier = app(TwoFactorVerifier::class);

    expect($verifier->consumeRecoveryCode($admin->id, P16I_RECOVERY))->toBeTrue();
    expect($verifier->consumeRecoveryCode($admin->id, P16I_RECOVERY))->toBeFalse();
});

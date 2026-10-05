<?php

use App\Support\Auth\Totp;

/**
 * RFC 6238 Appendix B test vectors, SHA-1, 6 digits (the last six of the 8-digit vectors).
 * The secret is base32 of the ASCII string "12345678901234567890" — an independent
 * reference, not derived from the implementation under test.
 */
const P16_RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

test('the TOTP code matches the RFC 6238 vector at T=59', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    expect(Totp::code(P16_RFC_SECRET, 59))->toBe('287082');
});

test('the TOTP code matches the RFC 6238 vector at T=1111111109', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    expect(Totp::code(P16_RFC_SECRET, 1111111109))->toBe('081804');
});

test('verify accepts the current code and one step either side, and rejects a code outside that window', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    $now = 1111111109;
    $current = Totp::code(P16_RFC_SECRET, $now);
    $previous = Totp::code(P16_RFC_SECRET, $now - 30);
    $farAway = Totp::code(P16_RFC_SECRET, $now + 300);

    expect(Totp::verify(P16_RFC_SECRET, $current, $now))->toBeTrue()
        ->and(Totp::verify(P16_RFC_SECRET, $previous, $now))->toBeTrue()
        ->and(Totp::verify(P16_RFC_SECRET, $farAway, $now))->toBeFalse();
});

test('generated secrets are 32 base32 characters', function () {
    expect(class_exists(Totp::class))->toBeTrue();
    expect(Totp::generateSecret())->toMatch('/^[A-Z2-7]{32}$/');
});

<?php

use App\Support\LoginRateLimiter;

/**
 * Batch 1 (G): one identifier must map to one bucket however it is written, and a single IP must
 * not be able to spray many accounts. (Lockout of a victim from another IP is a design trade-off
 * and is reported, not tested here.)
 */
test('the same phone number written in different formats shares one bucket', function () {
    for ($i = 0; $i < 20; $i++) {
        LoginRateLimiter::hit(1, '9876543210', '10.0.0.1');
    }

    expect(LoginRateLimiter::tooManyAttempts(1, '+91 98765 43210', '10.0.0.2'))->toBeTrue();
});

test('one IP trying many different accounts is stopped by a per-IP ceiling', function () {
    for ($i = 0; $i < 60; $i++) {
        LoginRateLimiter::hit(1, "student{$i}@example.com", '10.0.0.9');
    }

    expect(LoginRateLimiter::tooManyAttempts(1, 'fresh-victim@example.com', '10.0.0.9'))->toBeTrue();
});

test('positive control: a different IP is not blocked on a fresh account', function () {
    for ($i = 0; $i < 60; $i++) {
        LoginRateLimiter::hit(1, "student{$i}@example.com", '10.0.0.9');
    }

    expect(LoginRateLimiter::tooManyAttempts(1, 'fresh-victim@example.com', '10.0.0.77'))->toBeFalse();
});

test('positive control: a different phone number is not affected by another number\'s attempts', function () {
    for ($i = 0; $i < 20; $i++) {
        LoginRateLimiter::hit(1, '9876543210', '10.0.0.1');
    }

    expect(LoginRateLimiter::tooManyAttempts(1, '9123456789', '10.0.0.2'))->toBeFalse();
});

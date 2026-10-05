<?php

use App\Support\LoginRateLimiter;
use Illuminate\Support\Facades\Cache;

test('the list of IPs seen for an identifier is capped (e.g. at 50) and keeps the most recent', function () {
    $tenantId = 1;
    $identifier = 'cap-test@example.com';

    for ($i = 1; $i <= 60; $i++) {
        LoginRateLimiter::hit($tenantId, $identifier, "10.0.0.{$i}");
    }

    // The key is an HMAC of the identifier (Phase 16.1), so it is read through the limiter's own key builder.
    $key = (new ReflectionMethod(LoginRateLimiter::class, 'seenIpsKey'))->invoke(null, $tenantId, $identifier);
    $seenIps = Cache::get($key, []);

    expect($seenIps)->toHaveCount(50)
        ->and($seenIps)->toContain('10.0.0.60')
        ->and($seenIps)->not->toContain('10.0.0.1');
});

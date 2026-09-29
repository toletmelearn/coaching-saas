<?php

use App\Support\LoginRateLimiter;
use Illuminate\Support\Facades\Cache;

test('the list of IPs seen for an identifier is capped (e.g. at 50) and keeps the most recent', function () {
    $tenantId = 1;
    $identifier = 'cap-test@example.com';

    for ($i = 1; $i <= 60; $i++) {
        LoginRateLimiter::hit($tenantId, $identifier, "10.0.0.{$i}");
    }

    $seenIps = Cache::get("login-seen-ips:{$tenantId}:{$identifier}", []);

    expect($seenIps)->toHaveCount(50)
        ->and($seenIps)->toContain('10.0.0.60')
        ->and($seenIps)->not->toContain('10.0.0.1');
});

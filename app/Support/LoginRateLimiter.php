<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two independent limiters guard tenant login (see SECURITY.md "Rate limiting"):
 * tenant+identifier+IP (stops one machine brute-forcing one account) and
 * tenant+identifier alone (stops the same attack distributed across many IPs). Every IP
 * that ever hits the identifier-scoped limiter is recorded in a small cache set so an
 * owner/staff password reset can clear every per-IP bucket for that identifier too, not
 * just the identifier-wide one — otherwise a student who tried from several devices before
 * asking for help would still be locked out on whichever IP they try next.
 */
class LoginRateLimiter
{
    private const IP_MAX_ATTEMPTS = 5;

    private const IP_DECAY_SECONDS = 60;

    private const IDENTIFIER_MAX_ATTEMPTS = 20;

    private const IDENTIFIER_DECAY_SECONDS = 60 * 60;

    public static function tooManyAttempts(int $tenantId, string $identifier, string $ip): bool
    {
        return RateLimiter::tooManyAttempts(self::ipKey($tenantId, $identifier, $ip), self::IP_MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts(self::identifierKey($tenantId, $identifier), self::IDENTIFIER_MAX_ATTEMPTS);
    }

    public static function hit(int $tenantId, string $identifier, string $ip): void
    {
        RateLimiter::hit(self::ipKey($tenantId, $identifier, $ip), self::IP_DECAY_SECONDS);
        RateLimiter::hit(self::identifierKey($tenantId, $identifier), self::IDENTIFIER_DECAY_SECONDS);

        $seenIpsKey = self::seenIpsKey($tenantId, $identifier);
        $seenIps = Cache::get($seenIpsKey, []);

        if (! in_array($ip, $seenIps, true)) {
            $seenIps[] = $ip;
        }

        Cache::put($seenIpsKey, $seenIps, self::IDENTIFIER_DECAY_SECONDS);
    }

    public static function clearSuccessfulLogin(int $tenantId, string $identifier): void
    {
        self::clear($tenantId, $identifier);
    }

    /**
     * Clear every rate-limit bucket for every identifier this user could log in with
     * (email and/or phone) — used when an owner/staff resets a locked-out user's password.
     */
    public static function clearForUser(int $tenantId, User $user): void
    {
        foreach (array_filter([$user->email, $user->phone]) as $identifier) {
            self::clear($tenantId, $identifier);
        }
    }

    private static function clear(int $tenantId, string $identifier): void
    {
        RateLimiter::clear(self::identifierKey($tenantId, $identifier));

        $seenIpsKey = self::seenIpsKey($tenantId, $identifier);

        foreach (Cache::pull($seenIpsKey, []) as $ip) {
            RateLimiter::clear(self::ipKey($tenantId, $identifier, $ip));
        }
    }

    private static function ipKey(int $tenantId, string $identifier, string $ip): string
    {
        return sprintf('login:%d:%s:%s', $tenantId, strtolower($identifier), $ip);
    }

    private static function identifierKey(int $tenantId, string $identifier): string
    {
        return sprintf('login-identifier:%d:%s', $tenantId, strtolower($identifier));
    }

    private static function seenIpsKey(int $tenantId, string $identifier): string
    {
        return sprintf('login-seen-ips:%d:%s', $tenantId, strtolower($identifier));
    }
}

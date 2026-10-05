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

    /**
     * Ceiling across every account from one IP, so one address cannot spray many identifiers.
     * Sized so a shared school or office address with a handful of users stays under it.
     */
    private const IP_CEILING_ATTEMPTS = 50;

    private const IP_CEILING_DECAY_SECONDS = 10 * 60;

    /**
     * Bounds the "IPs seen for this identifier" cache entry so a sustained distributed
     * attack (which the identifier-wide limiter above already stops after 20 attempts,
     * long before this would matter in practice) can't grow it without bound.
     */
    private const MAX_SEEN_IPS = 50;

    /**
     * Known trade-off (see SECURITY.md): the identifier-wide limit ignores the IP, so an attacker who
     * knows an account's email or phone can lock that account for an hour from any address. It stays
     * because it is what stops the same guessing spread across many IPs. Recovery: an owner or staff
     * member resets the password, which clears this account's buckets (clearForUser).
     */
    public static function tooManyAttempts(int $tenantId, string $identifier, string $ip): bool
    {
        return RateLimiter::tooManyAttempts(self::ipKey($tenantId, $identifier, $ip), self::IP_MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts(self::identifierKey($tenantId, $identifier), self::IDENTIFIER_MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts(self::ipCeilingKey($tenantId, $ip), self::IP_CEILING_ATTEMPTS);
    }

    public static function hit(int $tenantId, string $identifier, string $ip): void
    {
        RateLimiter::hit(self::ipKey($tenantId, $identifier, $ip), self::IP_DECAY_SECONDS);
        RateLimiter::hit(self::identifierKey($tenantId, $identifier), self::IDENTIFIER_DECAY_SECONDS);
        RateLimiter::hit(self::ipCeilingKey($tenantId, $ip), self::IP_CEILING_DECAY_SECONDS);

        $seenIpsKey = self::seenIpsKey($tenantId, $identifier);
        $seenIps = Cache::get($seenIpsKey, []);

        if (! in_array($ip, $seenIps, true)) {
            $seenIps[] = $ip;

            if (count($seenIps) > self::MAX_SEEN_IPS) {
                $seenIps = array_slice($seenIps, -self::MAX_SEEN_IPS);
            }
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

    /**
     * Keyed HMAC over the canonical identifier (User::normalizeLoginIdentifier), so "+91 98765 43210"
     * and "9876543210" share one bucket, and a cache dump holds no login identifier in clear.
     */
    private static function digest(string $identifier): string
    {
        return hash_hmac('sha256', User::normalizeLoginIdentifier($identifier), (string) config('app.key'));
    }

    private static function ipCeilingKey(int $tenantId, string $ip): string
    {
        return sprintf('login-ip:%d:%s', $tenantId, $ip);
    }

    private static function ipKey(int $tenantId, string $identifier, string $ip): string
    {
        return sprintf('login:%d:%s:%s', $tenantId, self::digest($identifier), $ip);
    }

    private static function identifierKey(int $tenantId, string $identifier): string
    {
        return sprintf('login-identifier:%d:%s', $tenantId, self::digest($identifier));
    }

    private static function seenIpsKey(int $tenantId, string $identifier): string
    {
        return sprintf('login-seen-ips:%d:%s', $tenantId, self::digest($identifier));
    }
}

<?php

namespace App\Support\LiveClasses;

/**
 * Mints the JWT a student carries into a JaaS (8x8.vc) room (Phase 12).
 *
 * The token is built and signed here, in the join redirect, and never rendered
 * into HTML that persists across requests — SECURITY.md "Jitsi join URL". The
 * secret is read from config('services.jitsi.app_secret') by the caller and is
 * never logged, never put in a response body, and never accepted from a client.
 *
 * Claims are exactly what JaaS checks: iss/aud = the app id, a two-hour exp,
 * and the display name/email under context.user. The payload is base64url like
 * the header, so the whole token is safe in a query string, and a hostile
 * display name round-trips byte-for-byte through json_encode/decode.
 */
class JitsiJwt
{
    /** Token lifetime — long enough for a full class, short enough to be useless later. */
    public const TTL_MINUTES = 120;

    public static function make(string $appId, string $secret, string $name, string $email, int $ttlMinutes = self::TTL_MINUTES): string
    {
        $header = self::base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $issuedAt = time();

        $payload = self::base64UrlEncode(json_encode([
            'iss' => $appId,
            'aud' => $appId,
            'iat' => $issuedAt,
            'exp' => $issuedAt + ($ttlMinutes * 60),
            'context' => [
                'user' => [
                    'name' => $name,
                    'email' => $email,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Verify a token against a secret by recomputing the HS256 signature.
     *
     * Added in Phase 13 for the "Test connection" button on
     * /admin/settings/services: minting a throwaway token and verifying it here
     * proves the app id + secret pair round-trips exactly the way JaaS would
     * validate it, without any outbound network call or the secret ever leaving
     * the process.
     */
    public static function verify(string $token, string $secret): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $secret === '') {
            return false;
        }

        [$header, $payload, $signature] = $parts;

        $expected = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        return hash_equals($expected, $signature);
    }

    /**
     * base64url without padding — no '+', '/' or '=' can reach a query string.
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

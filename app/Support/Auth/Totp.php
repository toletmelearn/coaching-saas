<?php

namespace App\Support\Auth;

use Random\RandomException;
use RuntimeException;

/**
 * RFC 6238 time-based one-time passwords: HMAC-SHA1, 30-second steps, 6 digits. Verification
 * accepts the current step and one step either side to absorb clock drift, and returns the
 * matched step so the caller can refuse a code it has already accepted.
 */
final class Totp
{
    private const STEP = 30;

    private const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function code(string $base32Secret, int $timestamp): string
    {
        return self::codeForStep(self::decodeBase32($base32Secret), intdiv($timestamp, self::STEP));
    }

    public static function verify(string $base32Secret, string $code, int $timestamp): bool
    {
        return self::matchStep($base32Secret, $code, $timestamp) !== null;
    }

    /**
     * The time step a valid code matched, so the caller can refuse a step it already accepted.
     */
    public static function matchStep(string $base32Secret, string $code, int $timestamp): ?int
    {
        $key = self::decodeBase32($base32Secret);
        $currentStep = intdiv($timestamp, self::STEP);

        foreach ([-1, 0, 1] as $offset) {
            $step = $currentStep + $offset;

            if (hash_equals(self::codeForStep($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function generateSecret(): string
    {
        try {
            $bytes = random_bytes(20);
        } catch (RandomException $e) {
            throw new RuntimeException('Secure random source unavailable.', 0, $e);
        }

        return self::encodeBase32($bytes);
    }

    private static function codeForStep(string $key, int $step): string
    {
        $counter = pack('J', $step);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (ord($hash[$offset]) & 0x7F) << 24
            | (ord($hash[$offset + 1]) & 0xFF) << 16
            | (ord($hash[$offset + 2]) & 0xFF) << 8
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function encodeBase32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $output;
    }

    private static function decodeBase32(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(str_replace(' ', '', $secret))) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value !== false) {
                $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
            }
        }

        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }
}

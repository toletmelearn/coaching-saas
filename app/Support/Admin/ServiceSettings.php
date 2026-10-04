<?php

namespace App\Support\Admin;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Platform-level service credentials editable from /admin/settings/services
 * (Phase 13) — the "zero-developer" path: the operator never edits .env.
 *
 * Storage: the existing system_settings table (key → JSON value). The two true
 * secrets (the Bunny account key and the JaaS app secret) are encrypted at rest
 * with Crypt (APP_KEY) before hitting the database, so a database dump or a
 * leaked SELECT never exposes them; jitsi_app_id is stored plaintext because it
 * is a public identifier that appears inside every join URL.
 *
 * Read precedence is DB-first, config/env second: app:preflight, the Bunny
 * provider and the live-class join all read through here, so credentials set
 * purely from the UI pass deploy checks and actually serve traffic — while a
 * deployment that still keeps the keys in .env (or a test that sets
 * config('services.*')) behaves exactly as before.
 */
class ServiceSettings
{
    /** The three keys /admin/settings/services manages. */
    public const KEYS = ['bunny_account_api_key', 'jitsi_app_id', 'jitsi_app_secret'];

    /** Stored encrypted at rest (jitsi_app_id is intentionally not secret). */
    private const ENCRYPTED = ['bunny_account_api_key', 'jitsi_app_secret'];

    /**
     * DB value (decrypted) → config()/env() fallback → null.
     *
     * DB failures (table not migrated yet on a fresh clone, connection down)
     * must never break a read — preflight and video provisioning both call this
     * — so any throw falls through to the config fallback.
     */
    public static function get(string $key): ?string
    {
        try {
            $stored = SystemSetting::get($key);
        } catch (Throwable) {
            $stored = null;
        }

        if (is_array($stored)) {
            $value = $stored['value'] ?? null;

            if (! is_string($value)) {
                return self::configFallback($key);
            }

            if (($stored['encrypted'] ?? false) === true) {
                try {
                    return Crypt::decryptString($value);
                } catch (Throwable) {
                    // APP_KEY rotated since this was saved: report "not set" so
                    // preflight/health fail loudly instead of silently using a
                    // stale env value the operator thinks they replaced.
                    return null;
                }
            }

            return $value;
        }

        if (is_string($stored)) {
            // Rows written before the encrypted wrapper existed.
            return $stored;
        }

        return self::configFallback($key);
    }

    /** Write a value; encrypts when the key is a true secret. */
    public static function set(string $key, string $value): void
    {
        $payload = in_array($key, self::ENCRYPTED, true)
            ? ['encrypted' => true, 'value' => Crypt::encryptString($value)]
            : ['value' => $value];

        SystemSetting::set($key, $payload);
    }

    /** Whether any value (DB or config fallback) is currently resolvable. */
    public static function isConfigured(string $key): bool
    {
        $value = self::get($key);

        return $value !== null && $value !== '';
    }

    private static function configFallback(string $key): ?string
    {
        $value = match ($key) {
            'bunny_account_api_key' => config('services.bunny.account_api_key'),
            'jitsi_app_id' => config('services.jitsi.app_id'),
            'jitsi_app_secret' => config('services.jitsi.app_secret'),
            default => null,
        };

        return is_string($value) && $value !== '' ? $value : null;
    }
}

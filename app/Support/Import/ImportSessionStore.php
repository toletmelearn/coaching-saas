<?php

namespace App\Support\Import;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps an array payload only in the current session, encrypted, never in the database or
 * logs — used for both the import preview/confirm token and the credentials sheet. Payload
 * ownership (tenant_id, created_by) is checked by the caller on every read: session storage
 * alone isn't trusted as the tenant/user boundary (see TENANCY.md — never assume an ambient
 * scope is enough on its own).
 */
class ImportSessionStore
{
    public function __construct(
        private readonly Session $session,
        private readonly string $namespace,
        private readonly int $ttlMinutes,
    ) {}

    public function put(array $payload, ?string $token = null): string
    {
        $token = $token ?? Str::random(40);
        $payload['created_at'] = now()->toIso8601String();
        $this->session->put($this->key($token), Crypt::encryptString(json_encode($payload)));

        return $token;
    }

    public function get(string $token): ?array
    {
        $raw = $this->session->get($this->key($token));

        if ($raw === null) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($raw), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function isExpired(array $payload): bool
    {
        if (! isset($payload['created_at'])) {
            return true;
        }

        return Carbon::parse($payload['created_at'])->addMinutes($this->ttlMinutes)->isPast();
    }

    public function markConsumed(string $token, array $payload): void
    {
        $payload['consumed'] = true;
        $this->session->put($this->key($token), Crypt::encryptString(json_encode($payload)));
    }

    /**
     * Overwrites a payload with only its identity (tenant_id, created_by) and no
     * `created_at` — isExpired() then treats it as expired regardless of real elapsed
     * time, so "Clear now" shows the same friendly unavailable message as a genuine
     * expiry, to the rightful owner only. Used to wipe sensitive rows (temporary
     * passwords) immediately without losing the ownership check on the next request.
     */
    public function clearKeepingIdentity(string $token, array $payload): void
    {
        $minimal = [
            'tenant_id' => $payload['tenant_id'] ?? null,
            'created_by' => $payload['created_by'] ?? null,
        ];
        $this->session->put($this->key($token), Crypt::encryptString(json_encode($minimal)));
    }

    public function forget(string $token): void
    {
        $this->session->forget($this->key($token));
    }

    private function key(string $token): string
    {
        return "{$this->namespace}.{$token}";
    }
}

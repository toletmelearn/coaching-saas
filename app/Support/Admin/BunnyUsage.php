<?php

namespace App\Support\Admin;

use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Per-tenant Bunny Stream usage for /admin/institutes/{tenant}/bunny-usage
 * (Phase 13, feature E).
 *
 * Every tenant owns its own Stream library (AGENTS.md invariant #13), so Bunny
 * genuinely reports usage per tenant: GET https://api.bunny.net/videolibrary/{id}
 * with the platform account key returns StorageUsage (bytes stored), TrafficUsage
 * (bytes served this month) and VideoCount for exactly that tenant's library —
 * verified against https://bunny.net/docs/api-reference/core/stream-video-library/get-video-library.
 *
 * The library detail response also carries ApiKey (the tenant's own library
 * secret); it is deliberately never read, returned or rendered here.
 *
 * Local figures (lesson_videos summed inside runAs) are returned alongside as a
 * cross-reference and as the fallback when the API is unreachable — the page
 * always renders something useful.
 */
class BunnyUsage
{
    /**
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     storage_bytes: ?int,
     *     traffic_bytes: ?int,
     *     video_count: ?int,
     *     local_videos: int,
     *     local_bytes: int,
     * }
     */
    public function forTenant(Tenant $tenant): array
    {
        $local = $this->localUsage($tenant);

        $base = [
            'ok' => false,
            'error' => null,
            'storage_bytes' => null,
            'traffic_bytes' => null,
            'video_count' => null,
            'local_videos' => $local['videos'],
            'local_bytes' => $local['bytes'],
        ];

        if ($tenant->bunny_library_id === null) {
            // No library provisioned yet (first upload does it lazily) — not an
            // error, just nothing to ask Bunny about.
            return array_merge($base, ['ok' => true, 'error' => 'unprovisioned']);
        }

        $key = ServiceSettings::get('bunny_account_api_key');

        if ($key === null || $key === '') {
            return array_merge($base, ['error' => 'missing_key']);
        }

        try {
            $response = Http::withHeaders(['AccessKey' => $key])
                ->timeout(5)
                ->get('https://api.bunny.net/videolibrary/'.$tenant->bunny_library_id);
        } catch (ConnectionException) {
            return array_merge($base, ['error' => 'unreachable']);
        }

        if ($response->failed()) {
            return array_merge($base, ['error' => 'http_'.$response->status()]);
        }

        return array_merge($base, [
            'ok' => true,
            'storage_bytes' => $this->toInt($response->json('StorageUsage')),
            'traffic_bytes' => $this->toInt($response->json('TrafficUsage')),
            'video_count' => $this->toInt($response->json('VideoCount')),
        ]);
    }

    /**
     * What the application itself knows: videos and stored bytes for this
     * tenant, summed inside its runAs context (never another tenant's rows).
     *
     * @return array{videos: int, bytes: int}
     */
    public function localUsage(Tenant $tenant): array
    {
        return app(TenantContext::class)->runAs($tenant, function (): array {
            $bytes = LessonVideo::query()->sum('size_bytes');

            return [
                'videos' => (int) LessonVideo::query()->count(),
                'bytes' => (int) ($bytes === 0 ? 0 : $bytes),
            ];
        });
    }

    private function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

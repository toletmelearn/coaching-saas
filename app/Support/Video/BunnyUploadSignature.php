<?php

namespace App\Support\Video;

/**
 * Verified against https://bunny.net/docs/stream/tus-resumable-uploads:
 * AuthorizationSignature = SHA256_HEX(library_id + api_key + expiration_time + video_id).
 * library_id/api_key are the tenant's own library, never the account key.
 */
class BunnyUploadSignature
{
    public function sign(int $libraryId, string $apiKey, int $expiration, string $videoId): string
    {
        return hash('sha256', $libraryId.$apiKey.$expiration.$videoId);
    }

    /**
     * @return array{AuthorizationSignature: string, AuthorizationExpire: int, LibraryId: int, VideoId: string}
     */
    public function headersFor(int $libraryId, string $apiKey, string $videoId): array
    {
        $expires = now()->addMinutes((int) config('coaching.video_upload_signature_ttl_minutes', 60))->timestamp;

        return [
            'LibraryId' => $libraryId,
            'VideoId' => $videoId,
            'AuthorizationExpire' => $expires,
            'AuthorizationSignature' => $this->sign($libraryId, $apiKey, $expires, $videoId),
        ];
    }
}

<?php

namespace App\Support\Video;

/**
 * Verified against https://bunny.net/docs/stream-embed-token-authentication:
 * token = SHA256_HEX(token_security_key + video_id + expiration), expires = UNIX seconds.
 * Embed URL: https://player.mediadelivery.net/embed/{libraryId}/{videoId}?token=...&expires=...
 */
class BunnyEmbedTokenSigner
{
    public function sign(string $key, string $videoId, int $expiration): string
    {
        return hash('sha256', $key.$videoId.$expiration);
    }

    public function embedUrl(int $libraryId, string $videoId, string $tokenSecurityKey): string
    {
        $expires = now()->addMinutes((int) config('coaching.video_embed_ttl_minutes', 10))->timestamp;
        $token = $this->sign($tokenSecurityKey, $videoId, $expires);

        return "https://player.mediadelivery.net/embed/{$libraryId}/{$videoId}?token={$token}&expires={$expires}";
    }
}

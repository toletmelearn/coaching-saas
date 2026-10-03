<?php

namespace App\Support\LiveClasses;

/**
 * Builds the exact URL a student is redirected to when joining a class
 * (Phase 12): https://8x8.vc/{app_id}/{room}?jwt={token}.
 *
 * Only the server ever assembles this — the room name and the JWT are both
 * produced server-side, and nothing of it is written into a page, so a client
 * can neither pick a room nor replay a stale URL it found somewhere.
 */
class JitsiJoinUrl
{
    public static function build(string $appId, string $room, string $jwt): string
    {
        return sprintf(
            'https://8x8.vc/%s/%s?jwt=%s',
            rawurlencode($appId),
            rawurlencode($room),
            $jwt // already base64url — dots and dashes/underscores are unreserved
        );
    }
}

<?php

use App\Support\Video\BunnyEmbedTokenSigner;

// Formula verified against https://bunny.net/docs/stream-embed-token-authentication:
// token = SHA256_HEX(token_security_key + video_id + expiration), expires = UNIX seconds.

test('token is the SHA256 hex digest of key + video id + expiration, in that order', function () {
    $key = 'secret-token-key';
    $videoId = '11111111-2222-3333-4444-555555555555';
    $expires = 1_800_000_000;

    $expected = hash('sha256', $key.$videoId.$expires);

    $token = (new BunnyEmbedTokenSigner)->sign($key, $videoId, $expires);

    expect($token)->toBe($expected);
});

test('a different key produces a different token for the same video and expiration', function () {
    $videoId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $expires = 1_800_000_000;

    $signer = new BunnyEmbedTokenSigner;

    $tokenA = $signer->sign('tenant-a-key', $videoId, $expires);
    $tokenB = $signer->sign('tenant-b-key', $videoId, $expires);

    expect($tokenA)->not->toBe($tokenB);
});

test('the signed embed URL expires in the future within the configured window and carries token + expires', function () {
    config(['coaching.video_embed_ttl_minutes' => 10]);

    $before = now()->timestamp;

    $url = (new BunnyEmbedTokenSigner)->embedUrl(
        libraryId: 12345,
        videoId: 'cccccccc-dddd-eeee-ffff-000000000000',
        tokenSecurityKey: 'secret-token-key',
    );

    $after = now()->timestamp;

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toContain('player.mediadelivery.net/embed/12345/cccccccc-dddd-eeee-ffff-000000000000')
        ->and($query)->toHaveKeys(['token', 'expires'])
        ->and((int) $query['expires'])->toBeGreaterThan($before)
        ->and((int) $query['expires'])->toBeLessThanOrEqual($after + 601);
});

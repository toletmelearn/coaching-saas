<?php

use App\Support\Video\BunnyUploadSignature;

// Formula verified against https://bunny.net/docs/stream/tus-resumable-uploads:
// AuthorizationSignature = SHA256(library_id + api_key + expiration_time + video_id)
// (hex digest), library id/key are the tenant's own library, never the account key.

test('the TUS signature is the SHA256 hex digest of library id + api key + expiration + video id', function () {
    $libraryId = 98765;
    $apiKey = 'library-level-api-key';
    $expires = 1_800_000_000;
    $videoId = 'ffffffff-1111-2222-3333-444444444444';

    $expected = hash('sha256', $libraryId.$apiKey.$expires.$videoId);

    $signature = (new BunnyUploadSignature)->sign($libraryId, $apiKey, $expires, $videoId);

    expect($signature)->toBe($expected);
});

test('the required TUS headers are returned with the expected keys', function () {
    $headers = (new BunnyUploadSignature)->headersFor(
        libraryId: 98765,
        apiKey: 'library-level-api-key',
        videoId: 'ffffffff-1111-2222-3333-444444444444',
    );

    expect($headers)->toHaveKeys(['AuthorizationSignature', 'AuthorizationExpire', 'LibraryId', 'VideoId'])
        ->and($headers['LibraryId'])->toBe(98765)
        ->and($headers['VideoId'])->toBe('ffffffff-1111-2222-3333-444444444444')
        ->and($headers)->not->toHaveKey('ApiKey');
});

test('a signature computed with a different library key does not match', function () {
    $libraryId = 98765;
    $expires = 1_800_000_000;
    $videoId = 'ffffffff-1111-2222-3333-444444444444';

    $signer = new BunnyUploadSignature;

    $signatureA = $signer->sign($libraryId, 'tenant-a-library-key', $expires, $videoId);
    $signatureB = $signer->sign($libraryId, 'tenant-b-library-key', $expires, $videoId);

    expect($signatureA)->not->toBe($signatureB);
});

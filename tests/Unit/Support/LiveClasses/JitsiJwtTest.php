<?php

use App\Support\LiveClasses\JitsiJwt;

test('the JWT is a short-lived HS256 token carrying the JaaS claims', function () {
    $token = JitsiJwt::make('jitsi-app-id', 'app-secret-value', 'Asha Menon', 'asha@example.com');

    [$headerPart, $payloadPart] = explode('.', $token);
    $header = json_decode(base64_decode(strtr($headerPart, '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($payloadPart, '-_', '+/')), true);

    expect($header['alg'])->toBe('HS256')
        ->and($header['typ'])->toBe('JWT')
        ->and($payload['iss'])->toBe('jitsi-app-id')
        ->and($payload['aud'])->toBe('jitsi-app-id')
        ->and($payload['context']['user']['name'])->toBe('Asha Menon')
        ->and($payload['context']['user']['email'])->toBe('asha@example.com');

    // "Short expiry": two hours, give or take the seconds the test itself burned
    $ttl = $payload['exp'] - time();
    expect($ttl)->toBeGreaterThanOrEqual(119 * 60)
        ->and($ttl)->toBeLessThanOrEqual(121 * 60);
});

test('the signature is HMAC-SHA256 over the header and payload with the server-held secret', function () {
    $token = JitsiJwt::make('jitsi-app-id', 'app-secret-value', 'Asha Menon', 'asha@example.com');

    [$headerPart, $payloadPart, $signature] = explode('.', $token);

    $expected = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "{$headerPart}.{$payloadPart}", 'app-secret-value', true)
    ), '+/', '-_'), '=');

    expect($signature)->toBe($expected);

    // A token signed with a different secret must not reproduce this signature —
    // that is what makes the signature a check rather than decoration
    $impostor = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "{$headerPart}.{$payloadPart}", 'a-different-secret', true)
    ), '+/', '-_'), '=');

    expect($signature)->not->toBe($impostor);
});

test('hostile display names survive the payload round-trip and the token stays URL-safe', function () {
    $hostile = 'O\'Brien "X" <script>alert(1)</script> & Co';
    $token = JitsiJwt::make('jitsi-app-id', 'app-secret-value', $hostile, 'a+b_tag@example.com');

    // Base64url only: no '+', '/' or '=' that could mangle a query string
    expect($token)->toMatch('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/');

    [, $payloadPart] = explode('.', $token);
    $payload = json_decode(base64_decode(strtr($payloadPart, '-_', '+/')), true);

    expect($payload['context']['user']['name'])->toBe($hostile)
        ->and($payload['context']['user']['email'])->toBe('a+b_tag@example.com');
});

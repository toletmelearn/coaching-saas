<?php

use App\Support\LiveClasses\JitsiJoinUrl;
use App\Support\LiveClasses\JitsiJwt;

test('the join URL is the JaaS host plus app id plus room with the JWT as the jwt query parameter', function () {
    $url = JitsiJoinUrl::build('jitsi-app-id', 'room-Abc_123', 'header.payload.signature');

    expect($url)->toBe('https://8x8.vc/jitsi-app-id/room-Abc_123?jwt=header.payload.signature');
});

test('a hostile display name cannot break the join URL apart', function () {
    $jwt = JitsiJwt::make('jitsi-app-id', 'app-secret-value', 'Evil <script> & "quote"', 'a@b.co');
    $url = JitsiJoinUrl::build('jitsi-app-id', 'room-Abc', $jwt);

    expect(parse_url($url, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($url, PHP_URL_HOST))->toBe('8x8.vc')
        ->and($url)->toContain('jwt='.$jwt);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query['jwt'])->toBe($jwt);
});

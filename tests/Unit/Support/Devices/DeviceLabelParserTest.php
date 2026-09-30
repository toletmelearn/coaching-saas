<?php

use App\Support\Devices\DeviceLabelParser;

dataset('user_agents', [
    'Chrome on Android' => [
        'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'Chrome on Android',
    ],
    'Chrome on Windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Chrome on Windows',
    ],
    'Chrome on macOS' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Chrome on macOS',
    ],
    'Chrome on Linux' => [
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Chrome on Linux',
    ],
    'Edge on Windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
        'Edge on Windows',
    ],
    'Firefox on Android' => [
        'Mozilla/5.0 (Android 13; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0',
        'Firefox on Android',
    ],
    'Firefox on Windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Firefox on Windows',
    ],
    'Safari on iOS' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
        'Safari on iOS',
    ],
    'Safari on macOS' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
        'Safari on macOS',
    ],
    'Samsung Internet on Android' => [
        'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
        'Samsung Internet on Android',
    ],
    'Opera on Windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0',
        'Opera on Windows',
    ],
    'unrecognised user agent falls back' => [
        'SomeWeirdBotThing/1.0',
        'Unknown device',
    ],
    'empty user agent falls back' => [
        '',
        'Unknown device',
    ],
]);

test('the label parser maps a user agent to a Browser on OS label', function (string $userAgent, string $expected) {
    expect((new DeviceLabelParser)->parse($userAgent))->toBe($expected);
})->with('user_agents');

test('a null user agent falls back to Unknown device', function () {
    expect((new DeviceLabelParser)->parse(null))->toBe('Unknown device');
});

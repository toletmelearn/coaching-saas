<?php

use App\Support\LiveClasses\IstDateTime;

test('fromInput converts IST wall-clock string to UTC storage string', function () {
    // 18:00 IST = UTC+5:30, so subtract 5h30m → 12:30 UTC
    $result = IstDateTime::fromInput('2025-01-15T18:00');

    expect($result)->toBe('2025-01-15 12:30:00');
});

test('fromInput crosses midnight correctly (IST midnight = prior day 18:30 UTC)', function () {
    // 00:00 IST on 2025-01-15 = 2025-01-14 18:30 UTC
    $result = IstDateTime::fromInput('2025-01-15T00:00');

    expect($result)->toBe('2025-01-14 18:30:00');
});

test('fromInput returns null for strings that do not match the Y-m-d\TH:i format', function () {
    expect(IstDateTime::fromInput('not-a-date'))->toBeNull();
    expect(IstDateTime::fromInput('2025-01-15 18:00:00'))->toBeNull(); // space separator, seconds present
    expect(IstDateTime::fromInput('01/15/2025T18:00'))->toBeNull(); // wrong date order
});

test('fromInput to toFormValue is a lossless round-trip', function () {
    $input = '2025-06-20T09:30';

    $stored = IstDateTime::fromInput($input);
    expect($stored)->not->toBeNull();

    $roundTrip = IstDateTime::toFormValue($stored);
    expect($roundTrip)->toBe($input);
});

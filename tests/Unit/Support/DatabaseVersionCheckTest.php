<?php

use App\Support\DatabaseVersionCheck;

test('MySQL versions below 8.0.16 are rejected; 8.0.16 and above pass', function (string $version, bool $shouldFail) {
    $result = DatabaseVersionCheck::minimumViolationMessage($version);

    if ($shouldFail) {
        expect($result)->not->toBeNull()->toContain('MySQL');
    } else {
        expect($result)->toBeNull();
    }
})->with([
    ['5.7.44', true],
    ['8.0.15', true],
    ['8.0.16', false],
    ['8.0.17', false],
    ['8.4.0', false],
]);

test('MariaDB versions below 10.2.1 are rejected; 10.2.1 and above pass', function (string $version, bool $shouldFail) {
    $result = DatabaseVersionCheck::minimumViolationMessage($version);

    if ($shouldFail) {
        expect($result)->not->toBeNull()->toContain('MariaDB');
    } else {
        expect($result)->toBeNull();
    }
})->with([
    ['10.1.48-MariaDB', true],
    ['10.2.0-MariaDB', true],
    ['10.2.1-MariaDB', false],
    ['10.4.32-MariaDB', false],
]);

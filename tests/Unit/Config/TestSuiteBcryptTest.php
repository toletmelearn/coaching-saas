<?php

/**
 * Guards the test-suite's own bcrypt cost, not a Phase 9 feature: a 100-row import test
 * hashing 100 temporary passwords must not crawl under the suite's real algorithm cost.
 * Both phpunit.xml (sqlite) and phpunit.mysql.xml already set BCRYPT_ROUNDS=4, so this is
 * expected to already pass — it exists to catch a regression if either file's env value
 * is ever raised back toward a production-realistic cost.
 */
test('BCRYPT_ROUNDS is set low for the test suite', function () {
    expect((int) env('BCRYPT_ROUNDS'))->toBeLessThanOrEqual(4);
});

test('phpunit.xml pins BCRYPT_ROUNDS to a low value', function () {
    $xml = file_get_contents(base_path('phpunit.xml'));

    expect($xml)->toMatch('/<env name="BCRYPT_ROUNDS" value="([0-9]+)"\/>/');
    preg_match('/<env name="BCRYPT_ROUNDS" value="([0-9]+)"\/>/', $xml, $m);
    expect((int) $m[1])->toBeLessThanOrEqual(4);
});

test('phpunit.mysql.xml pins BCRYPT_ROUNDS to a low value', function () {
    $xml = file_get_contents(base_path('phpunit.mysql.xml'));

    expect($xml)->toMatch('/<env name="BCRYPT_ROUNDS" value="([0-9]+)"\/>/');
    preg_match('/<env name="BCRYPT_ROUNDS" value="([0-9]+)"\/>/', $xml, $m);
    expect((int) $m[1])->toBeLessThanOrEqual(4);
});

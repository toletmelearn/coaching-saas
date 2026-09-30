<?php

test('the default session idle lifetime is 30 days (43200 minutes) unless overridden', function () {
    // phpunit.mysql.xml/phpunit.xml don't override SESSION_LIFETIME, so this reads
    // whatever config/session.php falls back to when the env var is absent — the
    // value this phase changes from Laravel's stock 120-minute default so a student
    // isn't logged out every two hours (see docs/specs/phase-8-devices.md).
    expect(config('session.lifetime'))->toBe(43200);
});

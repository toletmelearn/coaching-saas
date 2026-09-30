<?php

test('config/session.php falls back to a 30-day (43200 minute) session lifetime', function () {
    // Asserts the source file's fallback default, not the resolved config('session.lifetime')
    // value — the resolved value depends on the developer's own local .env, which this test
    // has no business dictating (see SECURITY.md-adjacent phase-8 notes: SESSION_LIFETIME is
    // an env-controlled deploy setting, changed by hand per docs/specs/phase-8-devices.md).
    $contents = file_get_contents(config_path('session.php'));

    expect($contents)->toContain("env('SESSION_LIFETIME', 43200)");
});

test('.env.example documents the 30-day default', function () {
    $contents = file_get_contents(base_path('.env.example'));

    expect($contents)->toContain('SESSION_LIFETIME=43200');
});

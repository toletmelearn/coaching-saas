<?php

/**
 * The Phase 16.1 operating rules are written down where an operator will look for them.
 */
test('SECURITY.md documents forced admin 2FA, recovery codes, the reset command, read-only impersonation and the lock trade-off', function () {
    $text = (string) file_get_contents(base_path('SECURITY.md'));

    expect($text)->toContain('platform-admin:reset-2fa')
        ->and($text)->toContain('recovery code')
        ->and($text)->toContain('read-only')
        ->and($text)->toContain('lock');
});

test('DEPLOY_RUNBOOK documents the production 2FA enrolment requirement and SESSION_ENCRYPT (positive control: the runbook exists)', function () {
    $text = (string) file_get_contents(base_path('docs/DEPLOY_RUNBOOK.md'));

    expect(strlen($text))->toBeGreaterThan(100)
        ->and($text)->toContain('SESSION_ENCRYPT')
        ->and($text)->toContain('two-factor');
});

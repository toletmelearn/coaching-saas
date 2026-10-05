<?php

use App\Support\Admin\LogTailer;

/**
 * The admin log viewer shows redacted lines: emails, phone numbers and long digit runs are replaced
 * before anything reaches the page.
 */
test('emails, phone numbers and long digit runs are redacted (positive control: an ordinary line is unchanged)', function () {
    expect(class_exists(LogTailer::class))->toBeTrue();

    $raw = 'SQLSTATE duplicate: Ravi student.ravi@example.com phone 9876543210 or +91 98765 43210 card 4111111111111111';
    $redacted = LogTailer::redact($raw);

    expect($redacted)->not->toContain('student.ravi@example.com')
        ->and($redacted)->not->toContain('9876543210')
        ->and($redacted)->not->toContain('4111111111111111')
        ->and($redacted)->toContain('[email]')
        ->and($redacted)->toContain('[phone]')
        ->and($redacted)->toContain('[digits]');

    expect(LogTailer::redact('database exploded at line 42'))->toBe('database exploded at line 42');
});

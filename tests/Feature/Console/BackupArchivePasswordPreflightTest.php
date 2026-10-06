<?php

use App\Support\Preflight\PreflightChecks;

/**
 * Batch 1 (E): production must not run with an empty BACKUP_ARCHIVE_PASSWORD (backups would be
 * unencrypted), and the key must be documented in .env.example.
 */
function b1PreflightOutcome(string $id): ?array
{
    app()->instance('env', 'production');

    return collect(app(PreflightChecks::class)->run())->firstWhere('id', $id);
}

test('production preflight fails when BACKUP_ARCHIVE_PASSWORD is empty', function () {
    config(['backup.backup.password' => null]);

    $outcome = b1PreflightOutcome('backup_archive_password');

    expect($outcome)->not->toBeNull()
        ->and($outcome['passed'])->toBeFalse();
});

test('positive control: production preflight passes when BACKUP_ARCHIVE_PASSWORD is set', function () {
    config(['backup.backup.password' => 'a-long-random-archive-password']);

    $outcome = b1PreflightOutcome('backup_archive_password');

    expect($outcome)->not->toBeNull()
        ->and($outcome['passed'])->toBeTrue();
});

test('.env.example documents BACKUP_ARCHIVE_PASSWORD with no real value', function () {
    $line = collect(file(base_path('.env.example')))->first(fn (string $l) => str_starts_with(trim($l), 'BACKUP_ARCHIVE_PASSWORD'));

    expect($line)->not->toBeNull()
        ->and(trim((string) $line))->toBe('BACKUP_ARCHIVE_PASSWORD=');
});

<?php

/**
 * Batch 1 (E): the admin env editor's .env copies (APP_KEY and other secrets) must never go into a
 * backup archive, and archives are verified after they are written.
 */
test('the env-backups folder is excluded from the backup archive', function () {
    expect(config('backup.backup.source.files.exclude'))->toContain(storage_path('app/private/env-backups'));
});

test('positive control: the private disk stays in the backup, so attachments are still archived', function () {
    expect(config('backup.backup.source.files.include'))->toContain(storage_path('app/private'));
});

test('backup archives are verified after they are written', function () {
    expect(config('backup.backup.verify_backup'))->toBeTrue();
});

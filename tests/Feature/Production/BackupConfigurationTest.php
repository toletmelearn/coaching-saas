<?php

use Illuminate\Console\Scheduling\Schedule;

test('backups cover the database and the private notes storage, on a dedicated disk, with 14-day retention', function () {
    expect(config('backup.backup.source.databases'))->toContain(config('database.default'))
        ->and(config('backup.backup.source.files.include'))->toBe([storage_path('app/private')])
        ->and(config('backup.backup.destination.disks'))->toBe(['backups'])
        ->and(config('backup.cleanup.default_strategy.keep_all_backups_for_days'))->toBe(14)
        ->and(config('backup.cleanup.default_strategy.keep_daily_backups_for_days'))->toBe(0);
});

test('the backups disk is separate from the private notes disk it backs up', function () {
    expect(config('filesystems.disks.backups.root'))
        ->not->toBe(config('filesystems.disks.local.root'));
});

test('backup:run and backup:clean are scheduled daily', function () {
    $events = app(Schedule::class)->events();

    $backupRun = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'backup:run'));
    $backupClean = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'backup:clean'));

    expect($backupRun)->not->toBeNull()
        ->and($backupRun->expression)->toBe('0 2 * * *')
        ->and($backupClean)->not->toBeNull()
        ->and($backupClean->expression)->toBe('30 1 * * *');
});

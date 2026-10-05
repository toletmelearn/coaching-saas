<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Schema;

/**
 * Migration-drift guard (AppServiceProvider::boot) — the belt to
 * docs/DEPLOY_RUNBOOK.md §1.1a's braces. Three times a phase shipped code plus
 * a migration that was never applied locally (Phases 12, 13, 15) and the first
 * request 500'd; boot() now refuses to serve a schema that is missing a table
 * the code expects and says exactly what to run instead.
 *
 * Boot()'s wiring cannot fire inside Pest — PHP_SAPI is 'cli', so
 * runningInConsole() is true and boot() deliberately skips the check (the same
 * reason `php artisan migrate` works at all). These tests therefore drive the
 * exact methods boot() calls, clause by clause.
 */
test('the guard throws a friendly error when a required table is missing', function () {
    expect(fn () => AppServiceProvider::assertLocalSchemaCurrent('table_that_does_not_exist'))
        ->toThrow(RuntimeException::class, 'The local database schema is out of date');
});

test('the guard stays quiet when the schema is current', function () {
    // Positive control: the sentinel table every migrated schema has.
    expect(Schema::hasTable('consents'))->toBeTrue();

    expect(fn () => AppServiceProvider::assertLocalSchemaCurrent())
        ->not->toThrow(RuntimeException::class);
});

test('the guard never runs on console paths so migrate and the suite keep working', function () {
    expect(app()->runningInConsole())->toBeTrue()
        ->and(AppServiceProvider::shouldCheckSchemaDrift())->toBeFalse();
});

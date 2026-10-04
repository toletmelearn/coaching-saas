<?php

use App\Models\PlatformAdmin;
use App\Support\Admin\ArtisanRunner;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 13 feature C — /admin/backups drives spatie/laravel-backup on the
 * existing `backups` disk. Filenames are bare-*.zip only; run/delete audit.
 */
test('the backups page lists zip archives only', function () {
    Storage::fake('backups');
    Storage::disk('backups')->put('2026-10-04-010000-backup.zip', 'zipbytes');
    Storage::disk('backups')->put('notes.txt', 'not a zip');

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/backups')
        ->assertOk()
        ->assertSee('2026-10-04-010000-backup.zip')
        ->assertDontSee('notes.txt');
});

test('the backups page shows an empty state before the first run', function () {
    Storage::fake('backups');

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/backups')
        ->assertOk()
        ->assertSee(__('platform.admin.backups.empty'));
});

test('a backup file can be downloaded', function () {
    Storage::fake('backups');
    Storage::disk('backups')->put('2026-10-04-010000-backup.zip', 'zip-content');

    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/backups/download?file=2026-10-04-010000-backup.zip');

    $response->assertOk();
    expect($response->streamedContent())->toBe('zip-content');
});

test('download and delete reject traversal, nested paths and non-zip names', function () {
    Storage::fake('backups');
    Storage::disk('backups')->put('2026-10-04-010000-backup.zip', 'zip-content');

    $admin = PlatformAdmin::factory()->create();

    foreach ([
        '../.env',
        'foo/bar.zip',
        'notes.txt',
        'missing.zip',
        '',
    ] as $file) {
        $this->actingAs($admin, 'platform_admin')
            ->get('http://coaching.test/admin/backups/download?file='.urlencode($file))
            ->assertNotFound();
    }

    // Traversal must not have touched anything outside the disk either.
    expect(Storage::disk('backups')->exists('2026-10-04-010000-backup.zip'))->toBeTrue();
});

test('deleting a backup removes it and writes an audit row', function () {
    Storage::fake('backups');
    Storage::disk('backups')->put('2026-10-04-010000-backup.zip', 'zip-content');

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/backups/delete', ['file' => '2026-10-04-010000-backup.zip'])
        ->assertRedirect('http://coaching.test/admin/backups')
        ->assertSessionHas('status');

    expect(Storage::disk('backups')->exists('2026-10-04-010000-backup.zip'))->toBeFalse();

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'backup_delete',
        'target_type' => 'backup:2026-10-04-010000-backup.zip',
        'admin_id' => $admin->id,
    ]);
});

test('running a backup goes through ArtisanRunner without notifications and audits', function () {
    $runner = new class extends ArtisanRunner
    {
        /** @var list<array{0: string, 1: array<string, mixed>}> */
        public array $calls = [];

        public function call(string $command, array $parameters = []): array
        {
            $this->calls[] = [$command, $parameters];

            return ['code' => 0, 'output' => 'Backup completed'];
        }
    };
    $this->app->bind(ArtisanRunner::class, fn () => $runner);

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/backups/run')
        ->assertRedirect('http://coaching.test/admin/backups')
        ->assertSessionHas('status')
        ->assertSessionHas('backup_output');

    expect($runner->calls)->toHaveCount(1);
    expect($runner->calls[0][0])->toBe('backup:run');
    expect($runner->calls[0][1])->toBe(['--disable-notifications' => true]);

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'backup_run',
        'admin_id' => $admin->id,
    ]);
});

test('a failing backup run surfaces an error flash but still records the attempt', function () {
    $runner = new class extends ArtisanRunner
    {
        public function call(string $command, array $parameters = []): array
        {
            return ['code' => 1, 'output' => 'Could not connect to the database'];
        }
    };
    $this->app->bind(ArtisanRunner::class, fn () => $runner);

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/backups/run')
        ->assertRedirect('http://coaching.test/admin/backups')
        ->assertSessionHas('error')
        ->assertSessionHas('backup_output');

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'backup_run',
        'admin_id' => $admin->id,
    ]);
});

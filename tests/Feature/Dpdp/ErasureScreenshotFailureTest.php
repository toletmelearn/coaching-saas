<?php

use App\Models\ConsentAuditLog;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConsentFixtures;

/**
 * Screenshot files are deleted only after the erasure commits. If a file cannot be deleted,
 * the erasure still completes, the path is recorded in the audit log, and the owner sees a
 * plain message instead of an error page.
 */
test('erasure deletes screenshot files after the commit and the student row is anonymised (positive control: file exists before)', function () {
    $f = ConsentFixtures::erasureSetup();
    $path = inTenant($f['tenant'], fn () => Payment::find($f['payment']->id))->screenshot_path;
    Storage::fake('local');
    Storage::disk('local')->put($path, 'x');

    Storage::disk('local')->assertExists($path);

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), ['confirmation' => $f['student']->name])
        ->assertRedirect();

    Storage::disk('local')->assertMissing($path);
    expect(inTenant($f['tenant'], fn () => User::find($f['student']->id))->name)->toBe(ConsentFixtures::ERASED_NAME);
});

test('a screenshot that cannot be deleted is recorded in the audit log, the owner sees a plain message, and the erasure still completes (positive control: a clean erase has no warning)', function () {
    $f = ConsentFixtures::erasureSetup();
    $path = inTenant($f['tenant'], fn () => Payment::find($f['payment']->id))->screenshot_path;

    // Positive control: a clean erase on a separate student leaves no warning flashed.
    $clean = ConsentFixtures::erasureSetup('tenant-clean.coaching.test');
    Storage::fake('local');
    $this->actingAs($clean['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($clean['domain'], $clean['student']), ['confirmation' => $clean['student']->name])
        ->assertRedirect()
        ->assertSessionMissing('erasure_warning');

    $disk = Mockery::mock(Filesystem::class)->shouldIgnoreMissing();
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('disk unavailable'));
    Storage::set('local', $disk);

    $response = $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), ['confirmation' => $f['student']->name]);

    $response->assertRedirect()->assertSessionHas('erasure_warning', __('consents.erasure.file_warning'));

    inTenant($f['tenant'], function () use ($f, $path) {
        expect(User::find($f['student']->id)->name)->toBe(ConsentFixtures::ERASED_NAME);

        $log = ConsentAuditLog::where('action', 'screenshot_delete_failed')->first();
        expect($log)->not->toBeNull();
        expect($log->metadata['paths'])->toContain($path);
    });
});

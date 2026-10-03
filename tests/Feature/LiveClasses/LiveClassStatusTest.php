<?php

use App\Enums\LiveClassStatus;
use App\Models\LiveClass;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\LiveClassFixtures;

// === live-classes:update-status ===

test('a scheduled class stays scheduled until its start time arrives', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(3),
    ]);

    $this->artisan('live-classes:update-status')->assertSuccessful();

    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->status))->toBe(LiveClassStatus::Scheduled);
});

test('live-classes:update-status flips scheduled to live once starts_at has passed', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'scheduled',
    ]);

    $this->artisan('live-classes:update-status')->assertSuccessful();

    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->status))->toBe(LiveClassStatus::Live);
});

test('live-classes:update-status flips live to ended once ends_at has passed', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinutes(2),
        'status' => 'live',
    ]);

    $this->artisan('live-classes:update-status')->assertSuccessful();

    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->status))->toBe(LiveClassStatus::Ended);
});

test('a class with no ends_at lives for exactly the default 90 minutes', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(30),
        'ends_at' => null,
        'status' => 'scheduled',
    ]);

    // 30 minutes in: running, well inside the 90-minute default
    $this->artisan('live-classes:update-status')->assertSuccessful();
    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->status))->toBe(LiveClassStatus::Live);

    // 91 minutes in (30 + 61): past the default end, must close
    $this->travel(61)->minutes();
    $this->artisan('live-classes:update-status')->assertSuccessful();
    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->status))->toBe(LiveClassStatus::Ended);
});

test('a cancelled class is never transitioned, even after its start time', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addHour(),
        'status' => 'cancelled',
    ]);

    $this->artisan('live-classes:update-status')->assertSuccessful();

    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->status))->toBe(LiveClassStatus::Cancelled);
});

test('both live-class scheduled commands run every minute', function () {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    // Tolerant on the spacing Laravel pads the cron field with (it prints
    // `*  * * * *  php artisan …`); strict on the five-field cron, the php
    // artisan prefix, and the command name.
    expect($output)->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan live-classes:update-status/')
        ->and($output)->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan live-classes:close-stale-attendance/');
});

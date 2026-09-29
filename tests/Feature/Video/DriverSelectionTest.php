<?php

use App\Contracts\VideoProvider;
use App\Services\Video\BunnyVideoProvider;
use App\Services\Video\FakeVideoProvider;

test('the fake driver is bound by default', function () {
    config(['coaching.video_driver' => 'fake']);

    expect(app(VideoProvider::class))->toBeInstanceOf(FakeVideoProvider::class);
});

test('the bunny driver is bound when configured', function () {
    config(['coaching.video_driver' => 'bunny']);

    expect(app(VideoProvider::class))->toBeInstanceOf(BunnyVideoProvider::class);
});

// Reuses the passingPreflightConfig() helper defined in
// tests/Feature/Console/AppPreflightCommandTest.php (same global Pest test file scope).

test('app:preflight passes in production with the bunny driver and an account key present (positive control)', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['coaching.video_driver' => 'bunny']);
    config(['services.bunny.account_api_key' => 'test-account-key']);

    $this->artisan('app:preflight')->assertSuccessful();
});

test('app:preflight fails in production when VIDEO_DRIVER is fake', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['coaching.video_driver' => 'fake']);

    $this->artisan('app:preflight')->assertFailed();
});

test('app:preflight fails in production when the bunny driver has no account API key', function () {
    app()->instance('env', 'production');
    config(passingPreflightConfig());
    config(['coaching.video_driver' => 'bunny']);
    config(['services.bunny.account_api_key' => null]);

    $this->artisan('app:preflight')->assertFailed();
});

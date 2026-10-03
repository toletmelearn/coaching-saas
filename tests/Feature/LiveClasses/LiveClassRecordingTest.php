<?php

use Tests\Support\LiveClassFixtures;

test('the recording disclosure is off by default and only renders when JaaS recording is enabled', function () {
    // Strict: the key must exist and be boolean-false, not merely "not set"
    expect(config('coaching.live_classes_recording_enabled'))->toBeFalse();

    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    $url = "http://{$f['domain']}/live-classes/{$class->id}";

    // Off: the page renders without any recording notice
    $off = $this->actingAs($f['student'], 'tenant')->get($url);
    $off->assertOk()->assertDontSee(__('live_classes.recording_notice'));

    // Positive control: switching the deploy-time flag on adds the disclosure
    config(['coaching.live_classes_recording_enabled' => true]);
    $this->actingAs($f['student'], 'tenant')->get($url)
        ->assertOk()
        ->assertSee(__('live_classes.recording_notice'));
});

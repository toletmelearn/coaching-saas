<?php

use Tests\Support\LiveClassFixtures;
use Tests\Support\MobileSafetyChecker;

test('the live class page is mobile-safe', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}");

    // Positive control, same reasoning as MobileGuardTest: the app's own 404 page
    // would satisfy the checker vacuously, so require the real page to render first.
    $page->assertOk();

    expect(MobileSafetyChecker::violations($page->getContent()))->toBe([]);
});

test('the attendance report page is mobile-safe', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinutes(10),
        'status' => 'ended',
    ]);
    LiveClassFixtures::attend($class, $f['student']);

    $page = $this->actingAs($f['owner'], 'tenant')->get(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/attendance"
    );

    $page->assertOk();

    expect(MobileSafetyChecker::violations($page->getContent()))->toBe([]);
});

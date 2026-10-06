<?php

use App\Models\LiveClass;
use Carbon\CarbonImmutable;
use Tests\Support\LiveClassFixtures;

/**
 * Batch 1 (C): a teacher types a time in IST (the institute's zone). It must be stored as that
 * instant, not as the same wall-clock time in UTC, which is 5h30m later in reality.
 */
test('a class typed as 18:00 IST is stored as 12:30 UTC (the same instant)', function () {
    config(['coaching.live_classes_enabled' => true]);
    $f = LiveClassFixtures::setup();
    $when = CarbonImmutable::now('Asia/Kolkata')->addDays(3)->setTime(18, 0);

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'Evening class',
            'starts_at' => $when->format('Y-m-d\TH:i'),
        ]);

    $class = inTenant($f['tenant'], fn () => LiveClass::query()->where('title', 'Evening class')->firstOrFail());

    expect($class->starts_at->utc()->format('H:i'))->toBe('12:30')
        ->and($class->starts_at->timezone('Asia/Kolkata')->format('H:i'))->toBe('18:00');
});

test('a class typed as 23:30 IST on a day boundary keeps the IST date', function () {
    config(['coaching.live_classes_enabled' => true]);
    $f = LiveClassFixtures::setup();
    $when = CarbonImmutable::now('Asia/Kolkata')->addDays(3)->setTime(23, 30);

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'Late class',
            'starts_at' => $when->format('Y-m-d\TH:i'),
        ]);

    $class = inTenant($f['tenant'], fn () => LiveClass::query()->where('title', 'Late class')->firstOrFail());

    expect($class->starts_at->timezone('Asia/Kolkata')->toDateString())->toBe($when->toDateString())
        ->and($class->starts_at->timezone('Asia/Kolkata')->format('H:i'))->toBe('23:30');
});

test('after:now judges an IST time in IST: 30 minutes from now (IST) is accepted', function () {
    config(['coaching.live_classes_enabled' => true]);
    $f = LiveClassFixtures::setup();
    $soon = CarbonImmutable::now('Asia/Kolkata')->addMinutes(30);

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'Soon class',
            'starts_at' => $soon->format('Y-m-d\TH:i'),
        ])
        ->assertSessionHasNoErrors();
});

test('a class 2 hours in the past (IST) is refused by after:now (negative test)', function () {
    config(['coaching.live_classes_enabled' => true]);
    $f = LiveClassFixtures::setup();
    $past = CarbonImmutable::now('Asia/Kolkata')->subHours(2);

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'Past class',
            'starts_at' => $past->format('Y-m-d\TH:i'),
        ])
        ->assertSessionHasErrors('starts_at');
});

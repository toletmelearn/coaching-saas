<?php

use Tests\Support\LiveClassFixtures;

// === Language ===

test('lang/en/live_classes.php exists and every key the feature needs resolves', function () {
    expect(file_exists(lang_path('live_classes.php')))->toBeTrue();

    foreach ([
        'live_now',
        'join',
        'starts_in',
        'ended',
        'upcoming',
        'you_attended',
        'section_title',
        'absent',
        'not_yet_open',
        'already_ended',
        'cancelled',
        'cannot_edit_cancelled',
        'recording_notice',
    ] as $key) {
        expect(__('live_classes.'.$key))->not->toBe('live_classes.'.$key);
    }
});

// === Dashboard ===

test('the dashboard shows a Live now card to enrolled students only', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Dashboard live class',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // Positive control: the enrolled student sees the live card
    $enrolled = $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard");
    $enrolled->assertOk()
        ->assertSee(__('live_classes.live_now'))
        ->assertSee('Dashboard live class');

    // Negative: a same-tenant student with no enrolment in that course sees nothing
    $this->actingAs($f['outsider'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertDontSee(__('live_classes.live_now'))
        ->assertDontSee('Dashboard live class');
});

test('the dashboard announces a class starting within the next 24 hours', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Two hours from now',
        'starts_at' => now()->addHours(2),
    ]);

    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.starts_in', ['minutes' => 120]))
        ->assertSee('Two hours from now');
});

test('the dashboard shows no live-class UI while the feature is switched off', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Disabled-feature class',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    config(['coaching.live_classes_enabled' => false]);

    // Off: nothing renders (this branch passes regardless — the positive control below
    // is what ties the test to the feature existing at all)
    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertDontSee(__('live_classes.live_now'))
        ->assertDontSee('Disabled-feature class');

    // Positive control: flipping the flag back on brings the card back
    config(['coaching.live_classes_enabled' => true]);
    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.live_now'));
});

// === Course page ===

test('the course page has a live-classes section listing past and upcoming classes for enrolled students', function () {
    $f = LiveClassFixtures::setup();
    $past = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Past class on the course page',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
        'status' => 'ended',
    ]);
    $upcoming = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Upcoming class on the course page',
        'starts_at' => now()->addDay(),
    ]);

    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/courses/{$f['course']->slug}");

    $page->assertOk()
        ->assertSee(__('live_classes.section_title'))
        ->assertSee($past->title)
        ->assertSee($upcoming->title);
});

// === The class page itself ===

test('a live class page shows the Join button and the live badge', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(__('live_classes.live_now'))
        ->assertSee(__('live_classes.join'));
});

test('an upcoming class page shows the countdown instead of the Join button', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->addMinutes(45),
    ]);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(__('live_classes.starts_in', ['minutes' => 45]))
        ->assertDontSee(__('live_classes.join'));
});

test('an ended class page says so and offers no Join button', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'status' => 'ended',
    ]);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk()
        ->assertSee(__('live_classes.ended'))
        ->assertDontSee(__('live_classes.join'));
});

test('a student sees the you-attended indicator once they have an attendance row', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    $url = "http://{$f['domain']}/live-classes/{$class->id}";

    // Before joining: no indicator
    $this->actingAs($f['student'], 'tenant')->get($url)
        ->assertOk()
        ->assertDontSee(__('live_classes.you_attended'));

    // Join (opens the attendance row), then look again
    $this->actingAs($f['student'], 'tenant')->get("{$url}/join")->assertRedirect();

    $this->actingAs($f['student'], 'tenant')->get($url)
        ->assertOk()
        ->assertSee(__('live_classes.you_attended'));
});

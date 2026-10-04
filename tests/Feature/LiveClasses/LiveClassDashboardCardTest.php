<?php

use Carbon\CarbonInterface;
use Tests\Support\LiveClassFixtures;

/**
 * Phase 12.1 — the "Live classes" dashboard card.
 *
 * Both dashboards get the same three-state summary (live now / next inside the
 * window / nothing scheduled), computed from the caller's own context: an owner
 * or staff member across every course in the tenant, a student only across the
 * courses they are enrolled in. Owner and staff copy also links to the
 * cross-course list; the student card is read-only and never links into
 * /manage. Everything disappears while LIVE_CLASSES_ENABLED=false, keeping
 * Phase 12's "the dashboard widget renders nothing" guarantee.
 */

/**
 * The ":time" the card prints for a scheduled class — the same expression the
 * dashboard partial uses, so the assertion is the copy's contract rather than a
 * re-worded paraphrase of it. Today-only keeps "at 5:00 PM"; a start on another
 * day carries its date so a 24-hour-away class is never announced as a bare
 * clock time.
 */
function overviewCardTime(CarbonInterface $time): string
{
    return $time->isToday()
        ? $time->format('g:i A')
        : $time->format('D d M, g:i A');
}

// === Owner / staff ===

test('the owner dashboard card announces a live class with a Join link', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Owner dashboard live class',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.live_now', ['title' => 'Owner dashboard live class']))
        ->assertSee(url('/live-classes/'.$class->id.'/join'), false)
        ->assertSee(__('live_classes.card.view_all'));

    // Staff share the same dashboard view and the same manage bar.
    $this->actingAs($f['staff'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.live_now', ['title' => 'Owner dashboard live class']));
});

test('the owner dashboard card announces the next class inside the 24-hour window', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Owner dashboard next class',
        'starts_at' => now()->addHours(3),
    ]);

    $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.next', [
            'title' => 'Owner dashboard next class',
            'time' => overviewCardTime($class->starts_at),
        ]))
        ->assertSee(url('/live-classes/'.$class->id), false);
});

test('the owner dashboard card falls back to the empty state when nothing is scheduled', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.empty_owner'))
        ->assertSee(__('live_classes.card.view_all'))
        ->assertSee(url('/manage/live-classes'), false);
});

test('the owner dashboard card does not announce a class beyond the 24-hour window', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Three days out class',
        'starts_at' => now()->addDays(3),
    ]);

    $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.empty_owner'))
        ->assertDontSee('Three days out class');
});

// === Student ===

test('the student dashboard card says Live now with a Join link for an enrolled course', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Student dashboard live class',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.live_now', ['title' => 'Student dashboard live class']))
        ->assertSee(url('/live-classes/'.$class->id.'/join'), false);
});

test('the student dashboard card announces the next class inside the 24-hour window', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Student dashboard next class',
        'starts_at' => now()->addHours(5),
    ]);

    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.next_student', [
            'title' => 'Student dashboard next class',
            'time' => overviewCardTime($class->starts_at),
        ]));
});

test('the student dashboard card covers enrolled courses only and never links into manage', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Enrolled-only dashboard class',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // A same-tenant student with no enrolment in that course has nothing live:
    // the card says so instead of leaking the schedule.
    $this->actingAs($f['outsider'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.empty_student'))
        ->assertDontSee('Enrolled-only dashboard class')
        ->assertDontSee('/manage/live-classes', false);
});

test('the student dashboard card falls back to the empty state when nothing is upcoming', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee(__('live_classes.card.empty_student'))
        ->assertDontSee(__('live_classes.card.empty_owner'));
});

// === Feature flag ===

test('no live-class card renders on either dashboard while the feature is switched off', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Disabled card class',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    config(['coaching.live_classes_enabled' => false]);

    $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertDontSee(__('live_classes.card.empty_owner'))
        ->assertDontSee('Disabled card class')
        ->assertDontSee('/manage/live-classes', false);

    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertDontSee(__('live_classes.card.empty_student'))
        ->assertDontSee('Disabled card class');
});

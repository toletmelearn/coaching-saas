<?php

use App\Support\ProgressRecorder;
use Carbon\Carbon;

/**
 * Pure unit tests of App\Support\ProgressRecorder — no DB, no HTTP. The recorder
 * operates on plain arrays (current stored state in, updated state out) plus a
 * $now clock, per docs/specs/phase-7-progress.md "Accounting rules".
 *
 * Expected shape of $current / return value:
 * [
 *     'watched_seconds' => int,
 *     'duration_seconds' => ?int,
 *     'last_position_seconds' => int,
 *     'completed_at' => ?Carbon,
 *     'completed_manually' => bool,
 *     'last_heartbeat_at' => ?Carbon,
 *     'last_activity_at' => ?Carbon,
 * ]
 *
 * Expected shape of $input: ['position' => float, 'duration' => float, 'played' => float, 'ended' => bool]
 */
function freshState(): array
{
    return [
        'watched_seconds' => 0,
        'duration_seconds' => null,
        'last_position_seconds' => 0,
        'completed_at' => null,
        'completed_manually' => false,
        'last_heartbeat_at' => null,
        'last_activity_at' => null,
    ];
}

beforeEach(function () {
    $this->recorder = new ProgressRecorder;
});

test('the first heartbeat stores duration and is capped at 20 seconds of played time', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');

    $result = $this->recorder->applyHeartbeat(freshState(), [
        'position' => 30, 'duration' => 600, 'played' => 999, 'ended' => false,
    ], $now);

    expect($result['duration_seconds'])->toBe(600)
        ->and($result['watched_seconds'])->toBe(20);
});

test('a later duration within 10 percent of the stored value is accepted, a divergent one is ignored', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $state = $this->recorder->applyHeartbeat(freshState(), [
        'position' => 5, 'duration' => 600, 'played' => 5, 'ended' => false,
    ], $now);

    $withinTolerance = $this->recorder->applyHeartbeat($state, [
        'position' => 10, 'duration' => 630, 'played' => 5, 'ended' => false,
    ], $now->copy()->addSeconds(15));
    expect($withinTolerance['duration_seconds'])->toBe(630);

    $divergent = $this->recorder->applyHeartbeat($withinTolerance, [
        'position' => 20, 'duration' => 200, 'played' => 5, 'ended' => false,
    ], $now->copy()->addSeconds(30));
    expect($divergent['duration_seconds'])->toBe(630);
});

test('accepted delta is capped by wall-clock time since the last heartbeat, plus 5, and 60 seconds', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $state = $this->recorder->applyHeartbeat(freshState(), [
        'position' => 15, 'duration' => 600, 'played' => 15, 'ended' => false,
    ], $now);
    expect($state['watched_seconds'])->toBe(15);

    // Only 10 real seconds elapsed, but the client claims 500s of playback.
    $result = $this->recorder->applyHeartbeat($state, [
        'position' => 500, 'duration' => 600, 'played' => 500, 'ended' => false,
    ], $now->copy()->addSeconds(10));

    // min(played=500, secondsSince(10)+5=15, 60) = 15
    expect($result['watched_seconds'])->toBe(15 + 15);
});

test('a huge played value is still capped at 60 seconds even with a huge wall-clock gap', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $state = $this->recorder->applyHeartbeat(freshState(), [
        'position' => 15, 'duration' => 600, 'played' => 15, 'ended' => false,
    ], $now);

    $result = $this->recorder->applyHeartbeat($state, [
        'position' => 600, 'duration' => 600, 'played' => 100_000, 'ended' => false,
    ], $now->copy()->addHours(3));

    expect($result['watched_seconds'])->toBe(15 + 60);
});

test('seeking to the end without accumulating watched time does not complete the lesson', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $state = $this->recorder->applyHeartbeat(freshState(), [
        'position' => 5, 'duration' => 600, 'played' => 5, 'ended' => false,
    ], $now);

    // Playhead jumps to the end but no real playback (played=0) was reported.
    $result = $this->recorder->applyHeartbeat($state, [
        'position' => 599, 'duration' => 600, 'played' => 0, 'ended' => false,
    ], $now->copy()->addSeconds(5));

    expect($result['last_position_seconds'])->toBe(599)
        ->and($result['completed_at'])->toBeNull();
});

test('watching at least 85 percent of the duration completes the lesson automatically', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $state = freshState();

    // Accumulate to exactly 85% across several heartbeats (duration 100s -> need 85s).
    for ($i = 0; $i < 5; $i++) {
        $state = $this->recorder->applyHeartbeat($state, [
            'position' => min(100, $i * 20 + 17), 'duration' => 100, 'played' => 17, 'ended' => false,
        ], $now->copy()->addSeconds($i * 20));
    }

    expect($state['watched_seconds'])->toBeGreaterThanOrEqual(85)
        ->and($state['completed_at'])->not->toBeNull()
        ->and($state['completed_manually'])->toBeFalse();
});

test('completion is sticky: a later low-played heartbeat never un-completes the lesson', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $completed = freshState();
    $completed['watched_seconds'] = 90;
    $completed['duration_seconds'] = 100;
    $completed['completed_at'] = $now->copy();
    $completed['completed_manually'] = false;
    $completed['last_heartbeat_at'] = $now->copy();

    $result = $this->recorder->applyHeartbeat($completed, [
        'position' => 5, 'duration' => 100, 'played' => 1, 'ended' => false,
    ], $now->copy()->addMinutes(5));

    expect($result['completed_at'])->not->toBeNull();
});

test('watched_seconds never decreases even when a heartbeat arrives out of order', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');

    // First heartbeat: capped at 20s regardless of the claimed played time.
    $state = $this->recorder->applyHeartbeat(freshState(), [
        'position' => 40, 'duration' => 600, 'played' => 40, 'ended' => false,
    ], $now);
    expect($state['watched_seconds'])->toBe(20);

    // A later heartbeat, after real elapsed wall-clock time, ADDS to watched_seconds.
    $state = $this->recorder->applyHeartbeat($state, [
        'position' => 50, 'duration' => 600, 'played' => 10, 'ended' => false,
    ], $now->copy()->addSeconds(10));
    expect($state['watched_seconds'])->toBe(30);

    // Duplicate / out-of-order heartbeat reporting a smaller position and no new
    // played time must not reduce what's already been recorded.
    $result = $this->recorder->applyHeartbeat($state, [
        'position' => 10, 'duration' => 600, 'played' => 0, 'ended' => false,
    ], $now->copy()->addSeconds(11));

    expect($result['watched_seconds'])->toBe(30);
});

test('watched_seconds is capped at the duration and never exceeds it', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $state = freshState();
    for ($i = 0; $i < 3; $i++) {
        $state = $this->recorder->applyHeartbeat($state, [
            'position' => 30, 'duration' => 30, 'played' => 60, 'ended' => false,
        ], $now->copy()->addMinutes($i));
    }

    expect($state['watched_seconds'])->toBeLessThanOrEqual(30);
});

test('last_position_seconds is clamped to 0..duration', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $result = $this->recorder->applyHeartbeat(freshState(), [
        'position' => -5, 'duration' => 100, 'played' => 0, 'ended' => false,
    ], $now);
    expect($result['last_position_seconds'])->toBe(0);

    $result2 = $this->recorder->applyHeartbeat($result, [
        'position' => 999, 'duration' => 100, 'played' => 0, 'ended' => false,
    ], $now->copy()->addSeconds(1));
    expect($result2['last_position_seconds'])->toBe(100);
});

test('last_activity_at only updates when the accepted delta is greater than zero', function () {
    $now = Carbon::parse('2026-01-01 10:00:00');
    $state = $this->recorder->applyHeartbeat(freshState(), [
        'position' => 10, 'duration' => 100, 'played' => 10, 'ended' => false,
    ], $now);
    $firstActivity = $state['last_activity_at'];
    expect($firstActivity)->not->toBeNull();

    // Same position, zero real playback since — no accepted delta.
    $later = $now->copy()->addMinutes(10);
    $state = $this->recorder->applyHeartbeat($state, [
        'position' => 10, 'duration' => 100, 'played' => 0, 'ended' => false,
    ], $later);

    expect($state['last_activity_at']->eq($firstActivity))->toBeTrue();
});

test('resumePosition returns the last position when it is between 10 seconds and 95 percent of duration', function () {
    expect($this->recorder->resumePosition(50, 600))->toBe(50);
});

test('resumePosition returns 0 when the last position is below 10 seconds', function () {
    expect($this->recorder->resumePosition(5, 600))->toBe(0);
});

test('resumePosition returns 0 when the last position is at or beyond 95 percent of duration', function () {
    expect($this->recorder->resumePosition(580, 600))->toBe(0);
});

test('resumePosition returns 0 when duration is unknown', function () {
    expect($this->recorder->resumePosition(50, null))->toBe(0);
});

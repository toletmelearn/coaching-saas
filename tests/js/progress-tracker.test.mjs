// Node test-runner suite for resources/js/progress-tracker.js — the pure (DOM-free)
// playback-accounting logic driven by the lesson page's player wrapper. See
// docs/specs/phase-7-progress.md "Browser behaviour". Run via `composer test:js`
// (node --test "tests/js/**/*.test.mjs").
//
// progress-tracker.js exports:
//   - createTracker({ onSend }) => an object with:
//       .onTimeUpdate(currentTime, duration, nowMs)  // real player event
//       .onPause(nowMs) / .onEnded(nowMs) / .onHidden(nowMs) / .onPageHide(nowMs)
//     onSend(payload) is called with { position, duration, played, ended } whenever
//     the tracker decides to flush (every 15s of accepted played time, or on one of
//     the flush triggers above).
//   - resumePosition(lastPositionSeconds, durationSeconds) => number
//   - parseTimeUpdatePayload(data) => { seconds, duration } | null, accepting either
//     an object ({ seconds, duration }) or a JSON string of the same shape (Bunny's
//     player.js may deliver either).

import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { test } from 'node:test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const modulePath = path.resolve(__dirname, '../../resources/js/progress-tracker.js');

async function loadModule() {
    if (!existsSync(modulePath)) {
        throw new Error(`resources/js/progress-tracker.js does not exist yet at ${modulePath}`);
    }

    return import(modulePath);
}

test('accumulates played deltas between successive timeupdate events', async () => {
    const { createTracker } = await loadModule();
    const sent = [];
    const tracker = createTracker({ onSend: (payload) => sent.push(payload) });

    tracker.onTimeUpdate(10, 600, 1_000);
    tracker.onTimeUpdate(12, 600, 3_000); // +2s played, +2000ms wall clock

    tracker.onPause(4_000); // force a flush to inspect accumulated state
    assert.equal(sent.length, 1);
    assert.ok(sent[0].played >= 2 && sent[0].played < 3);
});

test('ignores a forward gap greater than 3 seconds (treated as a seek, not real playback)', async () => {
    const { createTracker } = await loadModule();
    const sent = [];
    const tracker = createTracker({ onSend: (payload) => sent.push(payload) });

    tracker.onTimeUpdate(10, 600, 1_000);
    tracker.onTimeUpdate(50, 600, 2_000); // 40s jump in 1s of wall clock -> a seek

    tracker.onPause(2_500);
    assert.equal(sent[0].played, 0);
});

test('ignores a backwards jump in currentTime (never reports negative played time)', async () => {
    const { createTracker } = await loadModule();
    const sent = [];
    const tracker = createTracker({ onSend: (payload) => sent.push(payload) });

    tracker.onTimeUpdate(30, 600, 1_000);
    tracker.onTimeUpdate(5, 600, 2_000); // seeked backwards

    tracker.onPause(2_100);
    assert.equal(sent[0].played, 0);
    assert.equal(sent[0].position, 5);
});

test('flushes automatically once 15 seconds of played time has accumulated', async () => {
    const { createTracker } = await loadModule();
    const sent = [];
    const tracker = createTracker({ onSend: (payload) => sent.push(payload) });

    let t = 0;
    let wall = 0;
    for (let i = 0; i < 16; i++) {
        t += 1;
        wall += 1_000;
        tracker.onTimeUpdate(t, 600, wall);
    }

    assert.ok(sent.length >= 1, 'expected an automatic flush after >=15s of played time');
    assert.ok(sent[0].played >= 15);
});

test('flushes on pause, on ended, on hidden, and on pagehide', async () => {
    const { createTracker } = await loadModule();

    for (const trigger of ['onPause', 'onEnded', 'onHidden', 'onPageHide']) {
        const sent = [];
        const tracker = createTracker({ onSend: (payload) => sent.push(payload) });
        tracker.onTimeUpdate(1, 600, 1_000);
        tracker.onTimeUpdate(2, 600, 2_000);
        tracker[trigger](2_100);
        assert.equal(sent.length, 1, `${trigger} should flush`);
    }
});

test('sets ended:true only when onEnded fires', async () => {
    const { createTracker } = await loadModule();
    const sent = [];
    const tracker = createTracker({ onSend: (payload) => sent.push(payload) });

    tracker.onTimeUpdate(1, 600, 1_000);
    tracker.onEnded(1_500);

    assert.equal(sent[0].ended, true);
});

test('does not flush while paused or the tab is hidden (no playback events)', async () => {
    const { createTracker } = await loadModule();
    const sent = [];
    const tracker = createTracker({ onSend: (payload) => sent.push(payload) });

    tracker.onTimeUpdate(1, 600, 1_000);
    tracker.onPause(1_100); // first flush, resets accumulator
    sent.length = 0;

    // No further timeupdate events while paused — nothing new to send.
    tracker.onHidden(5_000);
    assert.equal(sent.length, 0);
});

test('resumePosition mirrors the server rule: 10s to 95% of duration, else 0', async () => {
    const { resumePosition } = await loadModule();

    assert.equal(resumePosition(50, 600), 50);
    assert.equal(resumePosition(5, 600), 0);
    assert.equal(resumePosition(580, 600), 0);
    assert.equal(resumePosition(50, null), 0);
});

test('parseTimeUpdatePayload accepts a plain object', async () => {
    const { parseTimeUpdatePayload } = await loadModule();

    const result = parseTimeUpdatePayload({ seconds: 12.5, duration: 600 });
    assert.equal(result.seconds, 12.5);
    assert.equal(result.duration, 600);
});

test('parseTimeUpdatePayload accepts a JSON string with the same shape', async () => {
    const { parseTimeUpdatePayload } = await loadModule();

    const result = parseTimeUpdatePayload(JSON.stringify({ seconds: 8, duration: 300 }));
    assert.equal(result.seconds, 8);
    assert.equal(result.duration, 300);
});

test('parseTimeUpdatePayload returns null for malformed input rather than throwing', async () => {
    const { parseTimeUpdatePayload } = await loadModule();

    assert.equal(parseTimeUpdatePayload('not json'), null);
    assert.equal(parseTimeUpdatePayload(42), null);
    assert.equal(parseTimeUpdatePayload(null), null);
});

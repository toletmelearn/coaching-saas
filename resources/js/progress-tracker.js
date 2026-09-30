// Pure, DOM-free lesson-progress accounting. No `document`/`window` access anywhere in
// this file — resources/js/lesson-progress.js wires it up to a real player and to
// fetch(); tests/js/progress-tracker.test.mjs imports it directly under Node.
// See docs/specs/phase-7-progress.md "Browser behaviour".

const FLUSH_INTERVAL_SECONDS = 15;
const MAX_FORWARD_GAP_SECONDS = 3;
const RESUME_MIN_SECONDS = 10;
const RESUME_MAX_FRACTION = 0.95;

/**
 * @param {{ onSend: (payload: { position: number, duration: number, played: number, ended: boolean }) => void }} options
 */
export function createTracker({ onSend }) {
    let lastTime = null;
    let lastWallClockMs = null;
    let accumulatedPlayed = 0;
    let lastPosition = 0;
    let lastDuration = 0;
    let stopped = false;
    // True once a timeupdate has been observed since the last flush, even if it
    // contributed zero valid played seconds (an ignored seek/gap). Distinguishes "we
    // saw the player do something" from "truly idle, nothing to report" — a plain
    // idle tick (e.g. onHidden with no playback since the last flush) sends nothing.
    let hasActivitySinceFlush = false;

    function flush(nowMs, ended) {
        if (stopped) {
            return;
        }

        if (!hasActivitySinceFlush && !ended) {
            return;
        }

        onSend({
            position: lastPosition,
            duration: lastDuration,
            played: accumulatedPlayed,
            ended: Boolean(ended),
        });

        accumulatedPlayed = 0;
        hasActivitySinceFlush = false;
        lastWallClockMs = nowMs;
    }

    return {
        /**
         * A real playback tick from the player (HTML5 `timeupdate`, or the bunny
         * driver's equivalent). currentTime/duration are in seconds; nowMs is the
         * wall-clock time of this event (a plain number, e.g. Date.now(), never read
         * internally — always passed in, so this stays testable without a real clock).
         */
        onTimeUpdate(currentTime, duration, nowMs) {
            if (stopped) {
                return;
            }

            lastDuration = duration;
            hasActivitySinceFlush = true;

            if (lastTime !== null && lastWallClockMs !== null) {
                const delta = currentTime - lastTime;

                // Real forward playback only: ignore backwards jumps (seeking back,
                // or a stale/out-of-order event) and forward gaps bigger than a few
                // seconds (a seek forward, not genuine watching).
                if (delta > 0 && delta <= MAX_FORWARD_GAP_SECONDS) {
                    accumulatedPlayed += delta;
                }
            }

            lastTime = currentTime;
            lastPosition = currentTime;

            if (lastWallClockMs === null) {
                lastWallClockMs = nowMs;
            }

            if (accumulatedPlayed >= FLUSH_INTERVAL_SECONDS) {
                flush(nowMs, false);
            }
        },

        onPause(nowMs) {
            flush(nowMs, false);
        },

        onEnded(nowMs) {
            flush(nowMs, true);
        },

        onHidden(nowMs) {
            flush(nowMs, false);
        },

        onPageHide(nowMs) {
            flush(nowMs, false);
        },

        /**
         * Stops all future sends. Called once the server has told us (via a redirect,
         * 401, or 403 response) that this session/access is no longer valid — a
         * logged-out or disabled student's still-open tab must not keep hammering the
         * login page every 15 seconds of "playback".
         */
        stop() {
            stopped = true;
        },

        isStopped() {
            return stopped;
        },
    };
}

/**
 * Mirrors App\Support\ProgressRecorder::resumePosition() exactly: only offer to resume
 * when the stored position is between 10 seconds and 95% of the duration.
 */
export function resumePosition(lastPositionSeconds, durationSeconds) {
    if (!durationSeconds || durationSeconds <= 0) {
        return 0;
    }

    if (lastPositionSeconds < RESUME_MIN_SECONDS) {
        return 0;
    }

    if (lastPositionSeconds >= durationSeconds * RESUME_MAX_FRACTION) {
        return 0;
    }

    return lastPositionSeconds;
}

/**
 * Bunny's player.js `timeupdate` event may deliver its data as an object or as a JSON
 * string, depending on the embed. Never throws on malformed input.
 */
export function parseTimeUpdatePayload(data) {
    let value = data;

    if (typeof value === 'string') {
        try {
            value = JSON.parse(value);
        } catch {
            return null;
        }
    }

    if (
        value === null ||
        typeof value !== 'object' ||
        typeof value.seconds !== 'number' ||
        typeof value.duration !== 'number'
    ) {
        return null;
    }

    return { seconds: value.seconds, duration: value.duration };
}

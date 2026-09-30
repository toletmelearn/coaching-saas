import { createTracker, parseTimeUpdatePayload } from './progress-tracker.js';

document.addEventListener('DOMContentLoaded', initLessonProgress);

/**
 * Drives real-time watch-time tracking on the lesson page (resources/views/lessons/
 * show.blade.php). No-ops on every page without a player wrapper carrying
 * data-progress-url — that attribute is rendered server-side only for a genuinely
 * recordable, enrolled student (see LessonController::show()). See
 * docs/specs/phase-7-progress.md "Browser behaviour".
 */
export function initLessonProgress() {
    const wrapper = document.querySelector('[data-progress-url]');

    if (!wrapper) {
        return;
    }

    const progressUrl = wrapper.dataset.progressUrl;
    const driver = wrapper.dataset.driver;
    const startPosition = Number(wrapper.dataset.startPosition || 0);
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfTokenMeta ? csrfTokenMeta.content : '';

    const tracker = createTracker({ onSend: sendHeartbeat });

    function sendHeartbeat(payload) {
        // keepalive lets this request complete even if the tab is being closed/hidden
        // right as it fires (e.g. from the pagehide flush).
        fetch(progressUrl, {
            method: 'POST',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        })
            .then((response) => {
                // A logged-out/disabled student, or one whose access was revoked
                // mid-session, must not keep hammering the login page or a 403 every
                // 15 seconds — see progress-tracker.test.mjs "stop() prevents all
                // future sends".
                if (response.status === 401 || response.status === 403 || response.redirected) {
                    tracker.stop();
                }
            })
            .catch(() => {
                // Network failures are silent and simply retried at the next tick —
                // never block playback.
            });
    }

    if (driver === 'bunny') {
        initBunnyDriver(wrapper, tracker, startPosition);
    } else {
        initFakeDriver(wrapper, tracker, startPosition);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            tracker.onHidden(Date.now());
        }
    });

    window.addEventListener('pagehide', () => {
        tracker.onPageHide(Date.now());
    });
}

function initFakeDriver(wrapper, tracker, startPosition) {
    const video = wrapper.querySelector('video');

    if (!video) {
        return;
    }

    if (startPosition > 0) {
        video.addEventListener('loadedmetadata', () => {
            video.currentTime = startPosition;
        });
    }

    video.addEventListener('timeupdate', () => {
        tracker.onTimeUpdate(video.currentTime, video.duration || 0, Date.now());
    });

    video.addEventListener('pause', () => tracker.onPause(Date.now()));
    video.addEventListener('ended', () => tracker.onEnded(Date.now()));
}

function initBunnyDriver(wrapper, tracker, startPosition) {
    const iframe = wrapper.querySelector('iframe');

    if (!iframe) {
        return;
    }

    // player.js is only ever loaded on a page with a bunny-driven player — never
    // bundled into the main entry, never loaded from a CDN.
    import('player.js').then(({ default: playerjs }) => {
        const player = new playerjs.Player(iframe);

        player.on('ready', () => {
            if (startPosition > 0) {
                player.setCurrentTime(startPosition);
            }

            player.on('timeupdate', (data) => {
                const parsed = parseTimeUpdatePayload(data);

                if (parsed !== null) {
                    tracker.onTimeUpdate(parsed.seconds, parsed.duration, Date.now());
                }
            });

            player.on('pause', () => tracker.onPause(Date.now()));
            player.on('ended', () => tracker.onEnded(Date.now()));
        });
    });
}

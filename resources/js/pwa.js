/**
 * Registers the service worker and drives the "Install app" button (Android/Chrome)
 * and the "Add to Home Screen" hint (iOS Safari). Loaded only on tenant pages — see
 * resources/views/layouts/app.blade.php. Errors are swallowed everywhere: a browser
 * without service worker support, or one that blocks storage, must not break the page.
 */
function isStandalone() {
    try {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    } catch (e) {
        return false;
    }
}

function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    navigator.serviceWorker.register('/sw.js').catch(function () {
        // Feature-detected already; a registration failure (unsupported browser,
        // insecure context) is not user-facing.
    });
}

function initInstallButton() {
    const button = document.getElementById('pwa-install-button');

    if (!button || isStandalone()) {
        return;
    }

    let deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        button.hidden = false;
    });

    button.addEventListener('click', function () {
        if (!deferredPrompt) {
            return;
        }

        deferredPrompt.prompt();
        deferredPrompt = null;
        button.hidden = true;
    });

    window.addEventListener('appinstalled', function () {
        button.hidden = true;
    });
}

function isIos() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent || '');
}

function initIosHint() {
    const hint = document.getElementById('pwa-ios-hint');
    const dismissButton = document.getElementById('pwa-ios-hint-dismiss');

    if (!hint || !isIos() || isStandalone()) {
        return;
    }

    let dismissed = false;
    try {
        dismissed = window.localStorage.getItem('pwa-ios-hint-dismissed') === '1';
    } catch (e) {
        dismissed = false;
    }

    if (dismissed) {
        return;
    }

    hint.hidden = false;

    if (dismissButton) {
        dismissButton.addEventListener('click', function () {
            hint.hidden = true;
            try {
                window.localStorage.setItem('pwa-ios-hint-dismissed', '1');
            } catch (e) {
                // Storage blocked (private browsing, etc.) — dismissal just won't be
                // remembered next visit, which is fine.
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    registerServiceWorker();
    initInstallButton();
    initIosHint();
});

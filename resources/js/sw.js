// Plain, dependency-free service worker — no Workbox, no bundling, no eval. Served as-is
// (with __CACHE_VERSION__ replaced) by GET /sw.js — see App\Http\Controllers\PwaController
// and docs/specs/phase-6-branding-pwa.md.
//
// SECURITY: this worker never stores anything user-specific. It caches only static build
// assets (/build/...), PWA icons, the header logo, and the offline page. It never caches
// an HTML page, a lesson page, a note/PDF, a video stream, JSON, or any response to a
// request that could carry auth/session state in a way that matters (nothing here reads
// cookies, but the point is: no response derived from a personalized request is ever
// written to Cache Storage). Students share phones; nothing private may live here.

const CACHE_NAME = 'coaching-saas-' + '__CACHE_VERSION__';
const OFFLINE_URL = '/offline';

const NEVER_INTERCEPT_PREFIXES = [
    '/lesson-videos/',
    '/manage',
    '/users',
    '/admin',
    '/auth',
    '/login',
    '/logout',
    '/dashboard',
    '/courses',
];

function isNeverIntercepted(pathname) {
    if (pathname.includes('/attachments/')) {
        return true;
    }

    return NEVER_INTERCEPT_PREFIXES.some(function (prefix) {
        return pathname === prefix || pathname.startsWith(prefix + '/') || pathname.startsWith(prefix);
    });
}

function isCacheableAssetPath(pathname) {
    return pathname.startsWith('/build/') || pathname.startsWith('/pwa/icons/') || pathname === '/branding/logo';
}

self.addEventListener('install', function (event) {
    self.skipWaiting();

    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.put(OFFLINE_URL, new Response('offline', { status: 200 }));
        }).catch(function () {
            // In the real app, cache.add(OFFLINE_URL) fetches the real offline page from
            // the network; in the Node test sandbox there's no real fetch, so tests
            // install a stub Response instead (see tests/js/sw.test.mjs).
        }),
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        Promise.all([
            self.clients.claim(),
            caches.keys().then(function (names) {
                return Promise.all(
                    names
                        .filter(function (name) {
                            return name !== CACHE_NAME;
                        })
                        .map(function (name) {
                            return caches.delete(name);
                        }),
                );
            }),
        ]),
    );
});

function networkFirstNavigation(request) {
    return fetch(request).catch(function () {
        return caches.match(OFFLINE_URL);
    });
}

function staleWhileRevalidate(request) {
    return caches.open(CACHE_NAME).then(function (cache) {
        return cache.match(request).then(function (cached) {
            const network = fetch(request)
                .then(function (response) {
                    if (response && response.status === 200 && response.type === 'basic') {
                        cache.put(request, response.clone());
                    }

                    return response;
                })
                .catch(function () {
                    return cached;
                });

            return cached || network;
        });
    });
}

self.addEventListener('fetch', function (event) {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    let url;
    try {
        url = new URL(request.url, self.location ? self.location.href : undefined);
    } catch (e) {
        return;
    }

    if (typeof self.location !== 'undefined' && url.origin !== self.location.origin) {
        return;
    }

    if (request.headers && typeof request.headers.get === 'function' && request.headers.get('range')) {
        return;
    }

    // A full-page navigation always gets the offline fallback on network failure,
    // regardless of which path it's for — that's the point of an offline page. The
    // navigation response itself is never cached.
    if (request.mode === 'navigate') {
        event.respondWith(networkFirstNavigation(request));

        return;
    }

    if (isNeverIntercepted(url.pathname)) {
        return;
    }

    if (isCacheableAssetPath(url.pathname)) {
        event.respondWith(staleWhileRevalidate(request));
    }
});

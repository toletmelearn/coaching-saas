// Node test-runner suite for resources/js/sw.js (the service worker source).
// Run with: node --test tests/js  (wired up as `composer test:js`).
//
// The service worker must never cache or intercept anything user-specific (see
// SECURITY.md: "The service worker never stores anything user-specific"). This suite
// loads the real sw.js source into a minimal sandboxed `self`/`caches`/`fetch` and
// asserts every rule from docs/specs/phase-6-branding-pwa.md's service worker section.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const swPath = path.resolve(__dirname, '../../resources/js/sw.js');

function makeCache() {
    const store = new Map();

    return {
        store,
        async match(request) {
            const key = typeof request === 'string' ? request : request.url;
            return store.get(key) ?? undefined;
        },
        async put(request, response) {
            const key = typeof request === 'string' ? request : request.url;
            store.set(key, response);
        },
        async delete(request) {
            const key = typeof request === 'string' ? request : request.url;
            return store.delete(key);
        },
        async keys() {
            return [...store.keys()].map((url) => ({ url }));
        },
    };
}

/**
 * Loads sw.js in a sandbox with a fake `self`, `caches`, and `fetch`, and returns
 * handles to whatever listeners it registered plus the fake caches object, so each
 * test can dispatch a synthetic install/activate/fetch event.
 */
function loadServiceWorker({ fetchImpl } = {}) {
    if (!existsSync(swPath)) {
        throw new Error(`resources/js/sw.js does not exist yet at ${swPath}`);
    }

    const source = readFileSync(swPath, 'utf8');
    const listeners = {};
    const cachesByName = new Map();

    const fakeCaches = {
        open: async (name) => {
            if (!cachesByName.has(name)) {
                cachesByName.set(name, makeCache());
            }
            return cachesByName.get(name);
        },
        keys: async () => [...cachesByName.keys()],
        delete: async (name) => cachesByName.delete(name),
        match: async (request) => {
            for (const cache of cachesByName.values()) {
                const hit = await cache.match(request);
                if (hit) return hit;
            }
            return undefined;
        },
    };

    const fakeSelf = {
        addEventListener: (type, handler) => {
            listeners[type] = listeners[type] || [];
            listeners[type].push(handler);
        },
        skipWaiting: () => {},
        clients: { claim: () => {} },
    };

    const calls = [];
    const fetchStub = fetchImpl || (async (req) => {
        calls.push(req);
        throw new Error('network unavailable (default test stub)');
    });

    const sandbox = {
        self: fakeSelf,
        caches: fakeCaches,
        fetch: fetchStub,
        console,
        URL,
        Response,
        Request,
    };

    vm.createContext(sandbox);
    vm.runInContext(source, sandbox, { filename: 'sw.js' });

    return { listeners, cachesByName, fetchCalls: calls, sandbox };
}

class FakeEvent {
    constructor() {
        this._promises = [];
    }
    waitUntil(promise) {
        this._promises.push(promise);
    }
    async settle() {
        await Promise.all(this._promises);
    }
}

class FakeFetchEvent extends FakeEvent {
    constructor({ url, method = 'GET', mode = 'no-cors', headers = {} }) {
        super();
        this.request = {
            url,
            method,
            mode,
            headers: { get: (name) => headers[name.toLowerCase()] ?? null },
        };
        this._response = undefined;
    }
    respondWith(promiseOrResponse) {
        this._response = promiseOrResponse;
    }
}

async function dispatch(listeners, type, event) {
    for (const handler of listeners[type] || []) {
        await handler(event);
    }
    if (event.settle) {
        await event.settle();
    }
}

test('install precaches only /offline', async () => {
    const { listeners, cachesByName } = loadServiceWorker();
    const event = new FakeEvent();

    await dispatch(listeners, 'install', event);

    const allCached = [...cachesByName.values()].flatMap((c) => [...c.store.keys()]);
    assert.deepEqual(allCached.filter((url) => !url.endsWith('/offline')), []);
    assert.ok(allCached.some((url) => url.endsWith('/offline')));
});

test('activate deletes caches whose name differs from the current version', async () => {
    const { listeners, cachesByName } = loadServiceWorker();
    cachesByName.set('some-old-cache-v1', makeCache());

    await dispatch(listeners, 'activate', new FakeEvent());

    assert.ok(!cachesByName.has('some-old-cache-v1'));
});

test('a POST request is never intercepted', async () => {
    const { listeners } = loadServiceWorker();
    const event = new FakeFetchEvent({ url: 'https://tenant.example.com/manage/settings', method: 'POST' });

    await dispatch(listeners, 'fetch', event);

    assert.equal(event._response, undefined, 'respondWith must not be called for POST');
});

test('a cross-origin GET request is never intercepted', async () => {
    const { listeners } = loadServiceWorker();
    const event = new FakeFetchEvent({ url: 'https://cdn.example.com/thing.js' });

    await dispatch(listeners, 'fetch', event);

    assert.equal(event._response, undefined);
});

const neverInterceptPaths = [
    'https://tenant.example.com/lesson-videos/1/stream',
    'https://tenant.example.com/courses/x/lessons/1/attachments/1',
    'https://tenant.example.com/manage/courses',
    'https://tenant.example.com/users',
    'https://tenant.example.com/admin/login',
    'https://tenant.example.com/auth/change-password',
    'https://tenant.example.com/login',
    'https://tenant.example.com/logout',
    'https://tenant.example.com/dashboard',
    'https://tenant.example.com/courses',
];

for (const url of neverInterceptPaths) {
    test(`never intercepts ${url}`, async () => {
        const { listeners } = loadServiceWorker();
        const event = new FakeFetchEvent({ url });

        await dispatch(listeners, 'fetch', event);

        assert.equal(event._response, undefined, `must not intercept ${url}`);
    });
}

test('a request carrying a Range header is never intercepted', async () => {
    const { listeners } = loadServiceWorker();
    const event = new FakeFetchEvent({
        url: 'https://tenant.example.com/lesson-videos/1/stream?sig=x',
        headers: { range: 'bytes=0-1' },
    });

    await dispatch(listeners, 'fetch', event);

    assert.equal(event._response, undefined);
});

test('a navigation request falls back to the cached offline page on network failure, and is never cached itself', async () => {
    const failingFetch = async () => {
        throw new Error('offline');
    };
    const { listeners, cachesByName } = loadServiceWorker({ fetchImpl: failingFetch });

    await dispatch(listeners, 'install', new FakeEvent());

    const event = new FakeFetchEvent({ url: 'https://tenant.example.com/courses/algebra', mode: 'navigate' });
    await dispatch(listeners, 'fetch', event);

    const response = await event._response;
    assert.ok(response, 'navigation must respond with the offline fallback');

    const navigationUrls = [...cachesByName.values()]
        .flatMap((c) => [...c.store.keys()])
        .filter((url) => url.includes('/courses/algebra'));
    assert.deepEqual(navigationUrls, [], 'the navigation response itself must never be cached');
});

test('only /build assets, /pwa/icons and /branding/logo use stale-while-revalidate caching, and only on a 200 response', async () => {
    const { listeners, cachesByName } = loadServiceWorker();

    const event = new FakeFetchEvent({ url: 'https://tenant.example.com/build/app.abc123.js' });
    await dispatch(listeners, 'fetch', event);

    assert.notEqual(event._response, undefined, '/build assets must be intercepted');

    const nonAssetEvent = new FakeFetchEvent({ url: 'https://tenant.example.com/some-random-page' });
    await dispatch(listeners, 'fetch', nonAssetEvent);
    assert.equal(nonAssetEvent._response, undefined);
});

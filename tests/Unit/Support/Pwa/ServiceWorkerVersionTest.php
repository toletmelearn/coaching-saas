<?php

use App\Support\Pwa\ServiceWorkerVersion;

test('the version changes when the manifest content changes, sw.js source unchanged', function () {
    $swSource = "self.addEventListener('install', function () {});";

    $versionA = ServiceWorkerVersion::compute('{"app.js":"app-abc123.js"}', $swSource);
    $versionB = ServiceWorkerVersion::compute('{"app.js":"app-def456.js"}', $swSource);

    expect($versionA)->not->toBe($versionB);
});

test('the version changes when sw.js source changes, manifest unchanged', function () {
    $manifest = '{"app.js":"app-abc123.js"}';

    $versionA = ServiceWorkerVersion::compute($manifest, "self.addEventListener('install', function () {});");
    $versionB = ServiceWorkerVersion::compute($manifest, "self.addEventListener('install', function () { /* changed */ });");

    expect($versionA)->not->toBe($versionB);
});

test('the version is stable for identical inputs', function () {
    $manifest = '{"app.js":"app-abc123.js"}';
    $swSource = "self.addEventListener('install', function () {});";

    expect(ServiceWorkerVersion::compute($manifest, $swSource))
        ->toBe(ServiceWorkerVersion::compute($manifest, $swSource));
});

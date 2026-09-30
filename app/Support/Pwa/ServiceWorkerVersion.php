<?php

namespace App\Support\Pwa;

/**
 * Derives the service worker's cache-bust version from two inputs: the built Vite
 * manifest's own content (changes on every `npm run build` — new hashed asset
 * filenames) and sw.js's own source (changes whenever the worker's logic itself is
 * edited). Either one changing produces a different version, which is what makes
 * sw.js's activate handler actually evict the previous version's caches — see
 * resources/js/sw.js and App\Http\Controllers\PwaController::serviceWorker().
 *
 * A pure function (no filesystem access) so it's directly unit-testable without
 * needing to mutate real build output or source files.
 */
class ServiceWorkerVersion
{
    public static function compute(?string $manifestContents, string $swSource): string
    {
        $manifest = $manifestContents ?? 'dev';

        return substr(hash('crc32b', $manifest.'|'.$swSource), 0, 12);
    }
}

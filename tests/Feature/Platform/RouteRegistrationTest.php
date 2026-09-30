<?php

use Illuminate\Support\Facades\Route;

test('every /admin route is registered exactly once per domain', function () {
    $seen = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $key = ($route->domain() ?? 'no-domain-constraint').' '.$method.' '.$route->uri();

            expect($seen)->not->toHaveKey($key, "Route registered more than once: {$key}");
            $seen[$key] = true;
        }
    }

    expect($seen)->not->toBeEmpty();
});

test('php artisan route:cache succeeds — no duplicate route names across the central-domain loop', function () {
    // Reproduces the actual production failure mode: Route::domain() registers the same
    // route definitions once per central domain, so any ->name() on one of those routes
    // gets assigned to several different Route objects — route:list/normal request
    // routing tolerate this fine, but route:cache (part of `php artisan optimize`, part
    // of every deploy per docs/DEPLOY.md) serializes routes into a flat name-keyed
    // collection and throws if two Route objects share a name.
    $this->artisan('route:cache')->assertSuccessful();
    $this->artisan('route:clear')->assertSuccessful();
});

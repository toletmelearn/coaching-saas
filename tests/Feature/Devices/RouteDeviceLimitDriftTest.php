<?php

use Illuminate\Support\Facades\Route;

test('every route protected by active.tenant.user also carries device.limit', function () {
    $routesWithActiveTenantUser = collect(Route::getRoutes())->filter(
        fn ($route) => in_array('active.tenant.user', $route->gatherMiddleware(), true)
    );

    // Positive control: the test actually found real, already-existing routes to check —
    // otherwise the assertion below would trivially pass on an empty list.
    expect($routesWithActiveTenantUser)->not->toBeEmpty();

    $offenders = $routesWithActiveTenantUser
        ->reject(fn ($route) => in_array('device.limit', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

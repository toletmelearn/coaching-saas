<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Writable paths
    |--------------------------------------------------------------------------
    |
    | Paths php artisan app:preflight checks are writable by the web server
    | process. Overridable so tests can point this at a path that deliberately
    | doesn't exist (is_writable() on a missing path returns false, which is a
    | portable stand-in for "not writable" that doesn't depend on chmod
    | actually working the same way on every OS the test suite might run on).
    |
    */

    'writable_paths' => [
        storage_path(),
        base_path('bootstrap/cache'),
    ],
];

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

    /*
    |--------------------------------------------------------------------------
    | GD / FreeType availability
    |--------------------------------------------------------------------------
    |
    | The default institute icon (Phase 6) is drawn with GD's imagettftext(),
    | which needs FreeType support compiled into GD. Read from a config value
    | (rather than calling extension_loaded('gd')/gd_info() directly in the
    | command) so tests can mock a missing extension without needing a PHP
    | build that's actually missing it. Both true here in every real
    | environment that has GD compiled with FreeType (as production must).
    |
    */

    'gd_extension_loaded' => extension_loaded('gd'),
    'gd_freetype_supported' => extension_loaded('gd') && (gd_info()['FreeType Support'] ?? false),

    /*
    |--------------------------------------------------------------------------
    | Forced server version (checkMysqlVersion seam)
    |--------------------------------------------------------------------------
    |
    | Nullable string. When set, app:preflight reports this as the connected
    | server's version instead of running SELECT VERSION() — the seam that lets
    | a test exercise the below-minimum branch (an actually-old MySQL/MariaDB
    | would otherwise be the only way to see it, and the driver gate would make
    | it unreachable on SQLite at all). Mirrors the GD/FreeType seam above;
    | null in every real environment, so production always queries the real
    | server. See DatabaseVersionCheck for the 8.0.16 / 10.2.1 floors.
    |
    */

    'forced_database_version' => null,
];

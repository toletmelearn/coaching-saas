<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Central Domains
    |--------------------------------------------------------------------------
    |
    | Hostnames in this list are never looked up in tenant_domains — they are
    | where the platform marketing site / admin panel lives, plus the bare
    | local development hosts. See TENANCY.md for the fail-closed resolution
    | rule this list supports.
    |
    */

    'central_domains' => array_map(
        static fn (string $domain): string => trim(strtolower($domain)),
        explode(',', env('CENTRAL_DOMAINS', 'localhost,127.0.0.1,coaching.test,platform.coaching.test')),
    ),
];

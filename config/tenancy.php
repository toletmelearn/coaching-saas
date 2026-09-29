<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform domain
    |--------------------------------------------------------------------------
    |
    | The bare domain the platform itself is reachable on (e.g. mycoaching.app
    | in production, coaching.test locally). Used to derive the tenant base
    | domain below when TENANT_BASE_DOMAIN isn't set separately, and by
    | php artisan app:preflight to confirm production is configured.
    |
    */

    'platform_domain' => env('PLATFORM_DOMAIN', 'coaching.test'),

    /*
    |--------------------------------------------------------------------------
    | Tenant base domain
    |--------------------------------------------------------------------------
    |
    | Every tenant subdomain is "{subdomain}.{tenant_base_domain}" — this is
    | what php artisan tenant:create appends to the subdomain argument. In
    | production this is the domain the wildcard *.{tenant_base_domain} DNS
    | record and Cloudflare Origin Certificate cover (see docs/DEPLOY.md).
    |
    */

    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', env('PLATFORM_DOMAIN', 'coaching.test')),

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

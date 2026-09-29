<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare IP ranges
    |--------------------------------------------------------------------------
    |
    | These are the only IPs the app trusts to set X-Forwarded-For / X-Forwarded-
    | Proto (via the TrustProxies middleware — see bootstrap/app.php). A request
    | whose immediate TCP connection (REMOTE_ADDR) is NOT in this list has its
    | forwarded headers ignored entirely: $request->ip() falls back to
    | REMOTE_ADDR, and isSecure() falls back to the raw connection scheme. This
    | is what stops a client from forging "X-Forwarded-For: 1.2.3.4" to spoof
    | its IP (and so evade or share the login rate limiter) when it isn't
    | actually coming through Cloudflare.
    |
    | HOW TO REFRESH (Cloudflare rotates these occasionally):
    |   curl -s https://www.cloudflare.com/ips-v4
    |   curl -s https://www.cloudflare.com/ips-v6
    | ...and replace the two arrays below. Last refreshed: 2026-09-29, from the
    | URLs above.
    |
    */

    'ip_ranges' => [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

];

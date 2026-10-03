<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bunny Stream (protected lesson video)
    |--------------------------------------------------------------------------
    |
    | Account-level key only — the platform owns one Bunny account. Each tenant gets
    | its own Stream library (own library id, own library API key, own token security
    | key), provisioned lazily on that tenant's first upload and stored encrypted on
    | the tenants table — never here, never per-tenant in .env. See VIDEO.md and
    | docs/specs/phase-5-video.md.
    |
    */
    'bunny' => [
        'account_api_key' => env('BUNNY_STREAM_ACCOUNT_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jitsi Meet as a Service (JaaS, 8x8.vc) — live classes (Phase 12)
    |--------------------------------------------------------------------------
    |
    | One app id + secret per deployment (Decision A), used for exactly one
    | thing: signing the short-lived join JWT server-side. app:preflight
    | refuses production when coaching.live_classes_enabled is true and
    | either value is missing. The secret is never rendered, redirected or
    | logged — see SECURITY.md.
    |
    */
    'jitsi' => [
        'app_id' => env('JITSI_APP_ID'),
        'app_secret' => env('JITSI_APP_SECRET'),
    ],

];

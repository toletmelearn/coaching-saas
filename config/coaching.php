<?php

return [
    'max_attachment_mb' => env('COACHING_MAX_ATTACHMENT_MB', 20),

    'video_driver' => env('VIDEO_DRIVER', 'fake'),
    'max_video_mb' => env('COACHING_MAX_VIDEO_MB', 2048),
    'video_embed_ttl_minutes' => env('COACHING_VIDEO_EMBED_TTL_MINUTES', 10),
    'video_upload_signature_ttl_minutes' => env('COACHING_VIDEO_UPLOAD_SIGNATURE_TTL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | The 8 accent colours an owner can pick from in Settings (Phase 6). This is
    | the single source of truth: validation, the settings UI, and the
    | migration's default all read from this list — never a second hardcoded copy.
    | The first entry is also the default for a tenant that hasn't set one yet.
    |
    */

    'theme_presets' => [
        '#4f46e5', // indigo
        '#059669', // emerald
        '#dc2626', // red
        '#d97706', // amber
        '#2563eb', // blue
        '#7c3aed', // violet
        '#db2777', // pink
        '#0d9488', // teal
    ],

    'max_logo_mb' => 2,

    /*
    |--------------------------------------------------------------------------
    | Lesson progress (Phase 7)
    |--------------------------------------------------------------------------
    */

    'progress_heartbeat_max_attempts' => 12,
    'progress_heartbeat_decay_seconds' => 60,
    'inactive_days' => env('COACHING_INACTIVE_DAYS', 7),
];

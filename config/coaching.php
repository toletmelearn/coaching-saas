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

    /*
    |--------------------------------------------------------------------------
    | Devices (Phase 8)
    |--------------------------------------------------------------------------
    */

    'device_switch_flag' => env('COACHING_DEVICE_SWITCH_FLAG', 5),

    /*
    |--------------------------------------------------------------------------
    | Live classes (Phase 12)
    |--------------------------------------------------------------------------
    |
    | LIVE_CLASSES_ENABLED gates everything: the routes 404, the dashboard and
    | course-page sections render nothing. The feature uses a paste-any-URL
    | approach (Google Meet, Zoom, Jitsi, Whereby, etc.) — no JaaS keys are
    | required. Recording is a separate, default-off flag (Decision B): when
    | it is off, nothing in the UI claims a class is being recorded.
    |
    */

    'live_classes_enabled' => env('LIVE_CLASSES_ENABLED', true),
    'live_classes_recording_enabled' => env('LIVE_CLASSES_RECORDING_ENABLED', false),

    // Students may open the room this many minutes before starts_at (and the
    // show page switches from countdown to Join at the same threshold — one
    // source of truth for both the gate and the button).
    'live_class_join_window_minutes' => env('LIVE_CLASSES_JOIN_WINDOW_MINUTES', 15),

    // Length of a class scheduled without an explicit ends_at.
    'live_class_default_duration_minutes' => env('LIVE_CLASSES_DEFAULT_DURATION_MINUTES', 90),

    // An attendance row with no heartbeat for this long is closed by
    // live-classes:close-stale-attendance (left_at = last_seen_at).
    'live_class_stale_minutes' => env('LIVE_CLASSES_STALE_MINUTES', 5),

    // Heartbeat budget: 2 accepted beacons per 60 seconds per user per class;
    // the third in the window is a flat 429.
    'live_class_heartbeat_max_attempts' => env('LIVE_CLASSES_HEARTBEAT_MAX_ATTEMPTS', 2),
    'live_class_heartbeat_decay_seconds' => env('LIVE_CLASSES_HEARTBEAT_DECAY_SECONDS', 60),

    // The dashboard announces classes starting within this many hours.
    'live_class_upcoming_window_hours' => env('LIVE_CLASSES_UPCOMING_WINDOW_HOURS', 24),
];

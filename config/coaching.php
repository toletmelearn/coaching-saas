<?php

return [
    'max_attachment_mb' => env('COACHING_MAX_ATTACHMENT_MB', 20),

    'video_driver' => env('VIDEO_DRIVER', 'fake'),
    'max_video_mb' => env('COACHING_MAX_VIDEO_MB', 2048),
    'video_embed_ttl_minutes' => env('COACHING_VIDEO_EMBED_TTL_MINUTES', 10),
    'video_upload_signature_ttl_minutes' => env('COACHING_VIDEO_UPLOAD_SIGNATURE_TTL_MINUTES', 60),
];

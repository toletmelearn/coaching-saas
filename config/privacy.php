<?php

/**
 * Data inventory for DPDP Act 2023 compliance.
 *
 * Every purpose slug here must match a key in lang/en/consents.php `purposes` array.
 * A guard test (PrivacyConfigGuardTest) verifies this stays in sync.
 *
 * `retention => null` means [TO BE CONFIRMED BY OPERATOR]. See PRIVACY.md for the
 * full list of operator confirmations required before go-live.
 */
return [
    'data_categories' => [
        'identity' => [
            'fields' => ['name', 'phone_number', 'date_of_birth', 'email (optional)'],
            'purposes' => ['course_delivery', 'communication'],
            'legal_basis' => 'consent',
            'retention' => null,
        ],
        'guardian' => [
            'fields' => ['guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_email (optional)'],
            'purposes' => ['communication'],
            'legal_basis' => 'consent',
            'retention' => null,
        ],
        'session_device' => [
            'fields' => ['ip_address', 'user_agent', 'session_id', 'login_timestamps'],
            'purposes' => ['course_delivery'],
            'legal_basis' => 'legitimate_interest',
            'retention' => 'Until session ends or device is deregistered by the institute',
        ],
        'learning_progress' => [
            'fields' => ['watch_positions', 'completion_flags', 'last_accessed'],
            'purposes' => ['progress_tracking'],
            'legal_basis' => 'consent',
            'retention' => null,
        ],
        'payment' => [
            'fields' => ['amount', 'payment_date', 'upi_reference', 'payment_status'],
            'purposes' => ['course_delivery'],
            'legal_basis' => 'contract',
            'retention' => null,
        ],
        'live_class' => [
            'fields' => ['attendance_flag', 'join_time', 'watch_duration', 'class_recording_content'],
            'purposes' => ['course_delivery', 'progress_tracking', 'media_processing'],
            'legal_basis' => 'consent',
            'retention' => null,
        ],
    ],
];

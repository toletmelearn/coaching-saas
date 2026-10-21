<?php

/**
 * Data inventory for DPDP Act 2023 compliance.
 *
 * data_categories   — user-facing display table on the privacy page.
 *                     Every purpose slug here must match lang/en/consents.php `purposes`.
 *                     A guard test (PrivacyConfigGuardTest) verifies this stays in sync.
 *
 * column_inventory  — maps every personal-data column in each listed table to its
 *                     data_category slug.
 *
 * excluded_columns  — columns that are NOT personal data (or are handled separately by
 *                     security/operations) — each must carry a reason. A guard test
 *                     verifies that every column of every listed table is either in
 *                     column_inventory or excluded_columns; an uncovered column fails.
 *
 * `retention => null` means [TO BE CONFIRMED BY OPERATOR]. See PRIVACY.md.
 */
return [

    // -------------------------------------------------------------------------
    // User-facing data inventory (rendered as a table on the privacy page)
    // -------------------------------------------------------------------------

    'data_categories' => [
        'identity' => [
            'fields' => ['name', 'phone', 'date_of_birth', 'email (optional)'],
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
            'fields' => ['ip_address (in user_devices)', 'user_agent', 'device_id', 'device_label', 'login_timestamps'],
            'purposes' => ['course_delivery'],
            'legal_basis' => 'legitimate_interest',
            'retention' => 'Until session ends or device is deregistered by the institute',
        ],
        'learning_progress' => [
            'fields' => ['watched_seconds', 'last_position_seconds', 'completed_at', 'last_activity_at'],
            'purposes' => ['progress_tracking'],
            'legal_basis' => 'consent',
            'retention' => null,
        ],
        'payment' => [
            'fields' => ['amount_paise', 'upi_reference', 'screenshot_path', 'payment_status', 'payment_note'],
            'purposes' => ['course_delivery'],
            'legal_basis' => 'contract',
            'retention' => null,
        ],
        'live_class' => [
            'fields' => ['joined_at', 'last_seen_at', 'left_at', 'duration_seconds'],
            'purposes' => ['course_delivery', 'progress_tracking', 'media_processing'],
            'legal_basis' => 'consent',
            'retention' => null,
        ],
    ],

    // -------------------------------------------------------------------------
    // Column inventory — maps every personal-data column to its data_category.
    // Adding a column not listed here fails the PrivacyConfigGuardTest.
    // -------------------------------------------------------------------------

    'column_inventory' => [

        'users' => [
            'name' => 'identity',
            'email' => 'identity',
            'phone' => 'identity',
            'guardian_name' => 'guardian',
            'guardian_relationship' => 'guardian',
            'guardian_phone' => 'guardian',
            'guardian_email' => 'guardian',
            'last_login_at' => 'session_device',
        ],

        'user_devices' => [
            'device_id' => 'session_device',
            'label' => 'session_device',
            'user_agent' => 'session_device',
            'first_seen_at' => 'session_device',
            'last_seen_at' => 'session_device',
        ],

        'lesson_progress' => [
            'watched_seconds' => 'learning_progress',
            'last_position_seconds' => 'learning_progress',
            'duration_seconds' => 'learning_progress',
            'completed_at' => 'learning_progress',
            'completed_manually' => 'learning_progress',
            'last_heartbeat_at' => 'learning_progress',
            'last_activity_at' => 'learning_progress',
        ],

        'enrolments' => [
            'status' => 'course_delivery',
            'starts_at' => 'course_delivery',
            'ends_at' => 'course_delivery',
            'payment_note' => 'payment',
        ],

        'payments' => [
            'amount_paise' => 'payment',
            'upi_reference' => 'payment',
            'screenshot_path' => 'payment',
            'status' => 'payment',
            'rejection_reason' => 'payment',
            'submitted_at' => 'payment',
        ],

        'live_class_attendance' => [
            'joined_at' => 'live_class',
            'last_seen_at' => 'live_class',
            'left_at' => 'live_class',
            'duration_seconds' => 'live_class',
        ],
    ],

    // -------------------------------------------------------------------------
    // Excluded columns — not personal data, or handled outside DPDP consent scope.
    // Requires a reason. Must be kept in sync when columns are dropped.
    // -------------------------------------------------------------------------

    'excluded_columns' => [

        'users' => [
            'id' => 'primary key, not personal data',
            'tenant_id' => 'tenancy foreign key, not personal data',
            'email_verified_at' => 'email-verification operational timestamp, not DPDP-relevant personal data',
            'password' => 'credential hash — never exported or disclosed; handled by security policy',
            'role' => 'access control role, not personal data',
            'status' => 'account status flag, not personal data',
            'must_change_password' => 'security flag, not personal data',
            'remember_token' => 'session-security token, not personal data',
            'created_at' => 'system timestamp',
            'updated_at' => 'system timestamp',
        ],

        'user_devices' => [
            'id' => 'primary key',
            'tenant_id' => 'tenancy foreign key',
            'user_id' => 'foreign key to users',
            'revoked_at' => 'operational security action timestamp',
            'revoked_reason' => 'operational security action reason (admin-internal)',
            'notified_at' => 'notification tracking timestamp, not personal data',
            'created_at' => 'system timestamp',
            'updated_at' => 'system timestamp',
        ],

        'lesson_progress' => [
            'id' => 'primary key',
            'tenant_id' => 'tenancy foreign key',
            'lesson_id' => 'content foreign key',
            'course_id' => 'content foreign key (denormalised for query performance)',
            'user_id' => 'student foreign key',
            'created_at' => 'system timestamp',
            'updated_at' => 'system timestamp',
        ],

        'enrolments' => [
            'id' => 'primary key',
            'tenant_id' => 'tenancy foreign key',
            'course_id' => 'content foreign key',
            'user_id' => 'student foreign key',
            'enrolled_by' => 'staff user foreign key — not student personal data',
            'revoked_at' => 'operational timestamp',
            'revoked_by' => 'staff user foreign key — not student personal data',
            'approved_payment_id' => 'payment foreign key',
            'created_at' => 'system timestamp',
            'updated_at' => 'system timestamp',
        ],

        'payments' => [
            'id' => 'primary key',
            'tenant_id' => 'tenancy foreign key',
            'enrolment_id' => 'enrolment foreign key',
            'reviewed_at' => 'operational review timestamp',
            'reviewed_by' => 'staff user foreign key — not student personal data',
            'created_at' => 'system timestamp',
            'updated_at' => 'system timestamp',
        ],

        'live_class_attendance' => [
            'id' => 'primary key',
            'tenant_id' => 'tenancy foreign key',
            'live_class_id' => 'content foreign key',
            'user_id' => 'student foreign key',
            'created_at' => 'system timestamp',
            'updated_at' => 'system timestamp',
        ],
    ],
];

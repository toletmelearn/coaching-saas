<?php

/**
 * Phase 15 — DPDP consent UI strings.
 *
 * The four purposes and three collection methods are pinned by
 * tests/Feature/Consents/ConsentUiTest.php ("every consent string resolves"),
 * so every key listed there must exist here with a value that is not just the
 * key echoed back. Keep every value plain text (no HTML): the tests match
 * against e()-escaped output.
 */
return [

    // The notice shown on the student-creation form and the import preview.
    'notice_title' => 'Consent notice under the DPDP Act',
    'notice_body' => 'We collect this student\'s details to teach them, track their progress, message their guardian and — where the course uses them — process photos or videos from classes. By creating this student you confirm the guardian has seen and agreed to this notice. The guardian can withdraw any purpose later from the student\'s consent screen.',
    'notice_version_label' => 'Notice version',

    // Guardian details block.
    'guardian_heading' => 'Guardian details',

    'fields' => [
        'guardian_name' => 'Guardian name',
        'guardian_relationship' => 'Relationship to student',
        'guardian_phone' => 'Guardian phone',
        'guardian_email' => 'Guardian email (optional)',
        'method' => 'How was this consent collected?',
        'reason' => 'Reason for withdrawal',
        'confirmation' => 'Type the student\'s full name to confirm',
    ],

    'methods' => [
        'guardian_whatsapp' => 'Guardian agreed on WhatsApp',
        'guardian_in_person' => 'Guardian agreed in person',
        'guardian_signed_form' => 'Guardian signed the consent form',
    ],

    'purposes' => [
        'course_delivery' => 'Course delivery',
        'progress_tracking' => 'Progress tracking',
        'communication' => 'Communication',
        'media_processing' => 'Photo and video processing',
    ],

    'purposes_heading' => 'Consent given for (all four required)',

    'purpose_descriptions' => [
        'course_delivery' => 'Delivering the courses this student is enrolled in, including lessons and live classes.',
        'progress_tracking' => 'Recording lesson progress, completion and watch positions to show how the student is doing.',
        'communication' => 'Sending course updates and messages to the student and their guardian.',
        'media_processing' => 'Using photos or videos that include the student, for example from live classes.',
    ],

    // The consents screen.
    'list_heading' => 'Recorded consents',
    'record_heading' => 'Record a consent',
    'record_submit' => 'Record consent',
    'purpose_label' => 'Purpose',
    'status_label' => 'Status',
    'status_granted' => 'Granted',
    'status_withdrawn' => 'Withdrawn',
    'recorded_by_label' => 'Recorded by',
    'method_label' => 'Collection method',
    'granted_at_label' => 'Granted',
    'withdrawn_at_label' => 'Withdrawn',
    'withdrawn_reason_label' => 'Reason',

    // Withdrawal form.
    'withdraw_heading' => 'Withdraw consent',
    'withdraw_submit' => 'Withdraw consent',

    // Student data screen (export + erasure).
    'data_heading' => 'Student data',
    'export_button' => 'Download data export (JSON)',
    'erase_heading' => 'Erase this student',
    'erase_warning' => 'Erasure is permanent. The student\'s name becomes "Deleted Student", their email becomes a non-routable placeholder, phone and guardian details are removed, lesson progress and devices are deleted, enrolments and attendance records are removed (their key facts are kept in the audit log), and payments stay as anonymised financial records. Consent records survive as the audit trail.',
    'erase_submit' => 'Erase student',

    // Help page section.
    'help_title' => 'How we protect student data',
    'help_body' => 'We follow India\'s DPDP Act. Each student record carries the guardian\'s consent for four purposes — course delivery, progress tracking, communication and photo/video processing — and you can see, record or withdraw them from the student\'s consent screen. Withdrawing a purpose takes effect immediately: the student loses course access if course delivery is withdrawn, and progress data is deleted if tracking is withdrawn. Every student has a data screen with a full JSON export and a permanent erasure that keeps only what the audit trail needs.',

    'errors' => [
        'purposes_required' => 'Record the consent for all four purposes before creating the student.',
        'confirmation_mismatch' => 'The confirmation does not match the student\'s name.',
    ],
];

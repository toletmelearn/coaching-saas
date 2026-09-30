<?php

return [

    'nav_label' => 'Import from spreadsheet',
    'heading' => 'Import students from a spreadsheet',
    'download_template' => 'Download template',
    'choose_file' => 'Choose CSV file',
    'submit' => 'Preview import',

    'preview' => [
        'heading' => 'Preview',
        'ok_count' => ':count ready to import',
        'problem_count' => ':count with a problem',
        'confirm' => 'Create :count students',
        'course' => 'Enrol in a course (optional)',
        'ends_at' => 'Ends (optional)',
        'payment_note' => 'Payment note (optional)',
        'columns' => [
            'row' => '#',
            'name' => 'Name',
            'phone' => 'Phone',
            'email' => 'Email',
            'status' => 'Status',
        ],
    ],

    'status' => [
        'ok' => 'Ready to import',
        'invalid_phone' => 'Phone number is not valid',
        'invalid_email' => 'Email is not valid',
        'missing_contact' => 'Needs a phone or email',
        'duplicate_in_file' => 'Repeated in this file',
        'duplicate_in_tenant' => 'Already exists in your students',
    ],

    'already_processed' => 'This import was already processed.',
    'invalid_file' => 'That file does not look like a CSV spreadsheet.',
    'course_not_published' => 'That course is not available for enrolment.',
    'batch_failed' => 'Something went wrong and no students were created. Please try again.',

    'sheet' => [
        'heading' => 'Student logins',
        'name' => 'Name',
        'login' => 'Login',
        'password' => 'Temporary password',
        'whatsapp' => 'WhatsApp',
        'copy' => 'Copy message',
        'print' => 'Print',
        'download' => 'Download CSV',
        'clear_now' => 'Clear now',
        'expires_note' => 'This is only available for 15 minutes.',
    ],

    'sheet_unavailable' => "This sheet is no longer available. You can reset a student's password to get a new one.",

    'whatsapp_message' => 'Hello :name, your login for :institute — website: :url — login: :login — temporary password: :password. You will be asked to choose a new password when you first log in.',

];

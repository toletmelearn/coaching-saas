<?php

return [

    'nav_label' => 'Help',
    'heading' => 'Help',

    'sections' => [
        'add_one_student' => 'Adding one student',
        'bulk_import' => 'Adding many students from a spreadsheet',
        'whatsapp_sharing' => 'Sharing logins on WhatsApp',
        'enrolling' => 'Enrolling a student in a course',
        'uploading_from_phone' => 'Uploading a lecture from a phone',
        'preview_vs_paid' => 'Free preview vs paid lessons',
        'gone_quiet' => 'Who has gone quiet',
        'reset_password' => 'Resetting a password',
        'devices_per_student' => 'What "devices per student" means',
        'cannot_login' => 'When a student cannot log in',
    ],

    'body' => [
        'add_one_student' => 'Go to People, then "Add person". Fill in their name and phone or email, then save. You will see their temporary password on screen — share it with them yourself.',
        'bulk_import' => 'Go to People, then "Import from spreadsheet". Download the template, fill it in with one row per student, and upload it. You will see a preview before anything is created.',
        'whatsapp_sharing' => 'After adding students, you will see a sheet with a WhatsApp button for each one. Tap it to open WhatsApp with their login message already filled in — you just send it.',
        'enrolling' => 'Open the course, go to its "Enrolments" tab, pick the students, and save. You can also enrol students while importing them from a spreadsheet.',
        'uploading_from_phone' => 'Open a lesson and choose a video file from your phone. Keep files under the size shown on screen, and use MP4 where possible for the fastest upload.',
        'preview_vs_paid' => 'A free preview lesson can be watched by anyone, even before they log in — useful for showing off a course. All other lessons need an active enrolment.',
        'gone_quiet' => 'Open a course\'s Progress page and use the "Not started" or "Inactive" filter to see students who have not watched anything recently.',
        'reset_password' => 'On the People page, find the student and tap "Reset password". A new temporary password appears on screen for you to share with them.',
        'devices_per_student' => 'Each student can normally be logged in on one device at a time. Logging in on a new device signs the old one out. You can change the limit in Settings.',
        'cannot_login' => 'Reset their password from the People page. If they say they were signed out on a different phone, that is the one-device-per-student limit working as intended.',
    ],

    // Phase 12.1 — live classes, kept in its own group so the live-class copy
    // sits together instead of being scattered through the two arrays above.
    // The help page merges this group in after the sections above, so the
    // original ten keep their order and their keys.
    'live_classes' => [
        'sections' => [
            'schedule' => 'How to schedule a live class',
            'join' => 'How students join',
            'attendance' => 'How to see who attended',
        ],

        'body' => [
            'schedule' => 'Open the "Live classes" link in the header, tap the course, then "Schedule a live class". Give it a title and a start time and save — it appears for every student enrolled in that course.',
            'join' => 'Students see the class on their dashboard and on the course page. The room opens 15 minutes before the start time and stays open while it runs; they tap "Join live class" and the class opens in a new tab.',
            'attendance' => 'From the "Live classes" link in the header, find the class and tap "Attendance". You get every enrolled student — who came and how many minutes they stayed, absentees included — with an optional minutes filter and a CSV export.',
        ],
    ],

];

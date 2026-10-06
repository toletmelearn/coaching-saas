<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live classes (Phase 12)
    |--------------------------------------------------------------------------
    |
    | Every string a student or teacher sees around a live class. The first
    | block is the contract spelled out by tests/Feature/LiveClasses — the
    | flash reasons (not_yet_open / already_ended / cancelled /
    | cannot_edit_cancelled) are compared byte-for-byte against what the
    | controllers flash, so they must stay in lockstep with this file.
    |
    */

    'live_now' => 'Live now',
    'join' => 'Join live class',

    // Header link to the cross-course list (Phase 12.1). Rendered only while
    // coaching.live_classes_enabled is true, so the header can never advertise
    // a route that answers 404.
    'nav_label' => 'Live classes',
    'starts_in' => 'Starts in :minutes min',
    'ended' => 'This class has ended',
    'upcoming' => 'Upcoming',
    'you_attended' => 'You attended this class',
    'section_title' => 'Live classes',
    'absent' => 'Absent',

    // Refusal reasons flashed onto the class page (never a bare 403 — a student
    // who clicks too early deserves to be told when to come back). Kept free of
    // :placeholders so `__('live_classes.not_yet_open')` — as both the tests and
    // the controller call it — is the complete sentence.
    'not_yet_open' => 'This class has not opened yet. The room opens shortly before the start time.',
    'already_ended' => 'This class has already ended.',
    'cancelled' => 'This class was cancelled.',

    // Shown when a teacher tries to edit a cancelled class; the row stays as-is.
    'cannot_edit_cancelled' => 'A cancelled class cannot be edited.',

    // Flashed when the composite FK refuses a lesson delete because a live
    // class still links it — unlink the class (or delete it) first.
    'lesson_in_use' => 'This lesson has a linked live class. Remove the lesson link from the class (or delete the class) before deleting the lesson.',

    // Only rendered when coaching.live_classes_recording_enabled is true
    // (Decision B — off unless a paid JaaS recording add-on is attached).
    'recording_notice' => 'This class may be recorded.',

    'duration_minutes' => ':minutes min',

    'page' => [
        'description' => 'Live doubt-clearing sessions for this course. The room opens 15 minutes before the start time.',
        'join_hint' => 'Your mic and camera start muted — check them before you join.',
        'not_started' => 'Not started yet',
        'course' => 'Course',
        'when' => 'When',
    ],

    'status' => [
        'scheduled' => 'Scheduled',
        'live' => 'Live',
        'ended' => 'Ended',
        'cancelled' => 'Cancelled',
    ],

    // Button label for the external meeting URL (Batch 2.1 / 2.2).
    'meeting_url_button' => 'Join class',

    'manage' => [
        'heading' => 'Live classes',
        'schedule' => 'Schedule a live class',
        'edit' => 'Edit live class',
        'title' => 'Title',
        'description' => 'Description',
        'starts_at' => 'Starts at',
        'ends_at' => 'Ends at (optional)',
        'ends_at_hint' => 'Leave empty for the default :minutes-minute length.',
        'lesson' => 'Linked lesson (optional)',
        'lesson_none' => 'No lesson link',
        'meeting_url' => 'Meeting link (optional)',
        'meeting_url_hint' => 'Paste your Google Meet, Zoom, or Jitsi link. Students will see this when the class is scheduled.',
        'join_meeting' => 'Join meeting →',
        'meeting_url_invalid' => 'The meeting link must be a valid URL (max 2048 characters).',
        'meeting_url_https_required' => 'The meeting link must use https://.',
        'meeting_url_host_not_allowed' => 'The meeting link must be from Google Meet, Zoom, or Microsoft Teams.',
        'save' => 'Save class',
        'save_changes' => 'Save changes',
        'cancel_class' => 'Cancel class',
        'delete' => 'Delete',
        'back' => 'Back to the list',
        'empty' => 'No live classes scheduled yet.',
        'past' => 'Past',
        'upcoming_section' => 'Upcoming',
        'confirm_cancel' => 'Cancel this class? Students will no longer be able to join.',
        'confirm_delete' => 'Delete this class and its attendance records?',
        'cancelled_notice' => 'This class is cancelled and can no longer be edited.',
        'attendance' => 'Attendance',
    ],

    'report' => [
        'heading' => 'Class attendance',
        'student' => 'Student',
        'joined' => 'Joined',
        'left' => 'Left',
        'minutes' => 'Minutes',
        'still_in' => 'Still in class',
        'duration' => 'Duration',
        'absent_heading' => 'Did not attend',
        'absent_none' => 'Everyone enrolled attended.',
        'export' => 'Export CSV',
        'filter_hint' => 'Show only students who attended at least :minutes minutes.',
        'filter_all' => 'Show everyone',
        'no_attendance' => 'Nobody joined this class yet.',
        'enrolled_count' => ':count enrolled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard card (Phase 12.1)
    |--------------------------------------------------------------------------
    |
    | One three-state summary shared by both dashboards: live now, next inside
    | coaching.live_class_upcoming_window_hours, or nothing scheduled. The
    | owner/staff copy also points at the cross-course list; the student copy
    | is read-only and never links into /manage.
    |
    */

    'card' => [
        'live_now' => 'Live now: :title',
        'next' => 'Next: :title at :time',
        'next_student' => 'Next live class: :title at :time',
        'empty_owner' => 'No live classes scheduled — schedule one from Courses.',
        'empty_student' => 'No upcoming live classes.',
        'view_all' => 'View all live classes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-course list /manage/live-classes (Phase 12.1)
    |--------------------------------------------------------------------------
    |
    | The tenant-wide table an owner or staff member opens from the header:
    | every live class of every course in one place, filterable to upcoming,
    | past or all. Filters are read from ?filter= and anything unrecognised
    | means "all" — never an empty page pretending to be one.
    |
    */

    'list' => [
        'heading' => 'Live classes',
        'subtitle' => 'Every live class across your courses.',
        'columns' => [
            'course' => 'Course',
            'title' => 'Title',
            'starts' => 'Starts',
            'status' => 'Status',
            'attendance' => 'Attendance',
            'actions' => 'Actions',
        ],
        'filters' => [
            'upcoming' => 'Upcoming',
            'past' => 'Past',
            'all' => 'All',
        ],
        'empty' => 'No live classes yet. Schedule one from a course\'s live-classes page.',
        'empty_filter' => 'No classes match this filter.',
    ],
];

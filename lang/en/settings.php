<?php

return [
    'heading' => 'Institute settings',
    'save' => 'Save changes',
    'saved' => 'Settings saved.',

    'fields' => [
        'name' => 'Institute name',
        'contact_phone' => 'Contact phone',
        'contact_email' => 'Contact email',
        'theme_color' => 'Accent colour',
        'academic_year_end' => 'Academic year ends',
    ],

    'academic_year_end_hint' => 'Pre-fills the "Ends" date when enrolling a student. You can still change or clear it per enrolment.',

    'logo' => [
        'heading' => 'Logo',
        'current' => 'Current logo',
        'none' => 'No logo uploaded yet — a default icon with your institute\'s initial is used instead.',
        'upload' => 'Upload logo',
        'remove' => 'Remove logo',
        'confirm_remove' => 'Remove the logo and go back to the default icon?',
        'hint' => 'PNG, JPG or WebP, up to 2 MB. At least 256px and at most 4096px on each side.',
    ],

    'logo_errors' => [
        'required' => 'Choose a logo image to upload.',
        'format' => 'The logo must be a PNG, JPG or WebP image (not SVG).',
        'too_large' => 'The logo must be smaller than :max MB.',
        'invalid_image' => "That file couldn't be read as an image.",
        'too_small' => 'The logo must be at least :min px on each side.',
        'too_large_dimensions' => 'The logo must be at most :max px on each side.',
        'too_many_pixels' => 'That image is too large to process — try a smaller logo.',
    ],

    'offline' => [
        'message' => "You're offline. Check your connection and try again.",
    ],

    'pwa' => [
        'install' => 'Install app',
        'ios_hint' => 'To install: tap Share, then Add to Home Screen. Open this page in Safari.',
        'dismiss' => 'Dismiss',
    ],

    'validation' => [
        'name_required' => 'Please enter your institute name.',
        'name_max' => 'The institute name is too long.',
        'phone_invalid' => "That doesn't look like a valid phone number.",
        'email_invalid' => 'Please enter a valid email address.',
        'theme_color_invalid' => 'Please choose one of the available colours.',
        'academic_year_end_invalid' => 'Please enter a valid date.',
        'academic_year_end_too_far' => 'The academic year end date must be within the next 3 years.',
    ],
];

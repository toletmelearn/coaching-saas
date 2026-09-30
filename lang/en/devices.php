<?php

return [
    'heading' => 'Devices',

    'columns' => [
        'label' => 'Device',
        'first_seen' => 'First seen',
        'last_seen' => 'Last seen',
        'status' => 'Status',
    ],

    'status' => [
        'active' => 'Active',
        'signed_out' => 'Signed out',
    ],

    'reasons' => [
        'replaced' => 'replaced by a newer device',
        'owner' => 'signed out by an owner/staff member',
        'password_changed' => 'password changed',
        'password_reset' => 'password reset',
        'disabled' => 'account disabled',
        'logout' => 'signed out',
    ],

    'actions' => [
        'sign_out_one' => 'Sign out this device',
        'sign_out_all' => 'Sign out all devices',
        'confirm_sign_out_one' => 'Sign this device out? The student will need to log in again on it.',
        'confirm_sign_out_all' => 'Sign out all of this student\'s devices? They will need to log in again everywhere.',
    ],

    'switching_often' => 'Switching devices often',

    'empty' => 'No devices yet.',
];

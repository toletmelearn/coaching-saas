<?php

return [

    'index' => [
        'heading' => 'People',
        'new' => 'Add person',
        'update_action' => 'Update',
        'remove_action' => 'Remove',
        'empty' => 'No one here yet.',
    ],

    'create' => [
        'heading' => 'Add a person',
        'name' => 'Full name',
        'email' => 'Email',
        'phone' => 'Phone',
        'role' => 'Role',
        'submit' => 'Save',
    ],

    'roles' => [
        'owner' => 'Owner',
        'staff' => 'Staff',
        'student' => 'Student',
    ],

    'statuses' => [
        'active' => 'Active',
        'disabled' => 'Disabled',
    ],

    'temporary_password' => 'Temporary password',
    'temporary_password_for' => 'For :name (:identifier)',
    'temporary_password_share_warning' => 'Share this with the student. It will not be shown again.',

    'actions' => [
        'reset_password' => 'Reset password',
        'disable' => 'Disable',
        'enable' => 'Enable',
        'confirm_disable' => 'Disable this person? They will be logged out and unable to log back in until re-enabled.',
    ],

    'columns' => [
        'name' => 'Name',
        'phone' => 'Phone',
        'email' => 'Email',
        'role' => 'Role',
        'status' => 'Status',
        'actions' => 'Actions',
    ],

    'dashboard' => [
        'welcome' => 'Welcome back',
        'manage_people' => 'Manage people',
        'access_ended' => 'Access ended',
    ],
];

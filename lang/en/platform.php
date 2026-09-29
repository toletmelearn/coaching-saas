<?php

return [
    'admin_login_link' => 'Admin login',

    'home' => [
        'hero' => [
            'heading' => 'Run your coaching institute online — courses, notes, and protected video, all in one place.',
            'subheading' => 'Built for solo teachers and small coaching centres. Give every student their own login, share paid video lectures safely, and manage it all from one dashboard.',
            'cta' => 'Request a demo',
        ],
        'features' => [
            'heading' => 'Everything your institute needs',
            'courses' => [
                'title' => 'Structured courses',
                'body' => 'Organise lessons into chapters and courses, with free previews to attract new students.',
            ],
            'notes' => [
                'title' => 'Private notes',
                'body' => 'Share PDF notes with enrolled students only, never a public link.',
            ],
            'video' => [
                'title' => 'Protected video lectures',
                'body' => 'Upload lecture videos that only enrolled, logged-in students can watch — signed links and a moving watermark deter sharing.',
            ],
            'logins' => [
                'title' => 'Student logins',
                'body' => 'Every student gets their own account on your institute\'s own subdomain.',
            ],
            'enrolment' => [
                'title' => 'Manual enrolment',
                'body' => 'Enrol students yourself after confirming payment — no payment gateway needed to get started.',
            ],
            'dashboard' => [
                'title' => 'One dashboard',
                'body' => 'Manage courses, students, and staff from a single, simple dashboard built for mobile.',
            ],
        ],
        'how_it_works' => [
            'heading' => 'How it works',
            'step_1' => [
                'title' => 'We set up your institute',
                'body' => 'Request a demo and we create your institute\'s own subdomain and owner login.',
            ],
            'step_2' => [
                'title' => 'Add your courses',
                'body' => 'Upload lessons, notes, and video lectures, and enrol your students.',
            ],
            'step_3' => [
                'title' => 'Students learn',
                'body' => 'Your students log in on your institute\'s own subdomain and start learning.',
            ],
        ],
        'pricing' => [
            'heading' => 'Pricing',
            'body' => 'Coming soon.',
        ],
        'footer' => [
            'text' => 'A platform for coaching institutes.',
        ],
        'demo_form' => [
            'heading' => 'Request a demo',
            'name' => 'Your name',
            'phone' => 'Phone number',
            'email' => 'Email (optional)',
            'institute_name' => 'Institute name',
            'city' => 'City',
            'message' => 'Anything else? (optional)',
            'submit' => 'Request a demo',
            'success' => "Thanks — we've received your request and will be in touch soon.",
            'errors' => [
                'name_required' => 'Please enter your name.',
                'phone_required' => 'Please enter a phone number.',
                'institute_name_required' => 'Please enter your institute\'s name.',
                'city_required' => 'Please enter your city.',
            ],
        ],
    ],

    'admin' => [
        'nav' => [
            'dashboard' => 'Dashboard',
            'institutes' => 'Institutes',
            'demo_requests' => 'Demo requests',
            'logout' => 'Log out',
        ],
        'dashboard' => [
            'heading' => 'Dashboard',
            'active_institutes' => 'Active institutes',
            'suspended_institutes' => 'Suspended institutes',
            'total_students' => 'Total students',
            'total_courses' => 'Total courses',
            'new_demo_requests' => 'New demo requests',
        ],
        'institutes' => [
            'heading' => 'Institutes',
            'new' => 'New institute',
            'search_placeholder' => 'Search by name or subdomain',
            'empty' => 'No institutes found.',
            'columns' => [
                'name' => 'Name',
                'domain' => 'Subdomain',
                'status' => 'Status',
                'owner' => 'Owner',
                'students' => 'Students',
                'courses' => 'Courses',
                'created_at' => 'Created',
            ],
            'create' => [
                'heading' => 'New institute',
                'name' => 'Institute name',
                'subdomain' => 'Subdomain',
                'owner_name' => 'Owner name',
                'owner_email' => 'Owner email',
                'owner_phone' => 'Owner phone',
                'submit' => 'Create institute',
            ],
            'created' => [
                'heading' => 'Institute created',
                'login_url' => 'Login URL',
                'identifier' => 'Owner login',
                'temporary_password' => 'Temporary password',
                'warning' => 'This password is shown once — copy it now and share it securely with the owner.',
            ],
            'errors' => [
                'subdomain_format' => 'Subdomain must be 3-30 characters, lowercase letters/digits/hyphens only, and not start or end with a hyphen.',
                'subdomain_reserved' => 'That subdomain is reserved and cannot be used.',
                'subdomain_taken' => 'That subdomain is already in use.',
                'owner_contact_required' => 'Provide an owner email or phone number.',
            ],
            'show' => [
                'heading' => 'Institute',
                'status' => 'Status',
                'domain' => 'Domain',
                'students' => 'Students',
                'courses' => 'Courses',
                'owners' => 'Owners',
                'created_at' => 'Created',
                'suspend' => 'Suspend',
                'reactivate' => 'Reactivate',
                'confirm_suspend' => 'Suspend this institute? Its site will become unavailable to everyone, and any logged-in users will be logged out.',
                'confirm_reactivate' => 'Reactivate this institute? Its site will become available again.',
                'reset_owner_password' => 'Reset owner password',
                'reset_owner_password_for' => 'New temporary password for :name',
                'no_owner' => 'This institute has no owner account.',
            ],
        ],
        'demo_requests' => [
            'heading' => 'Demo requests',
            'empty' => 'No demo requests yet.',
            'columns' => [
                'name' => 'Name',
                'phone' => 'Phone',
                'email' => 'Email',
                'institute_name' => 'Institute',
                'city' => 'City',
                'status' => 'Status',
                'created_at' => 'Requested',
                'actions' => 'Actions',
            ],
            'statuses' => [
                'new' => 'New',
                'contacted' => 'Contacted',
            ],
            'mark_contacted' => 'Mark as contacted',
        ],
    ],

    'suspended' => [
        'heading' => 'This institute is temporarily unavailable.',
        'message' => 'Please check back later, or contact the institute directly.',
    ],
];

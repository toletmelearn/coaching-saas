<?php

return [

    'challenge' => [
        'heading' => 'Enter your authentication code',
        'help' => 'Open your authenticator app and enter the 6-digit code, or use one of your recovery codes.',
        'submit' => 'Verify',
    ],

    'setup' => [
        'heading' => 'Set up two-factor sign-in',
        'help' => 'Add this account to an authenticator app (Google Authenticator, Authy, 1Password), then enter the 6-digit code it shows.',
        'secret' => 'Or enter this key manually',
        'submit' => 'Turn on two-factor sign-in',
        'replace_help' => 'Two-factor sign-in is already on. To replace it, enter a current code from your authenticator app or one unused recovery code first.',
        'current_code' => 'Current code or recovery code',
    ],

    'recovery' => [
        'heading' => 'Save your recovery codes',
        'help' => 'Each code works once. Keep them somewhere safe — they will not be shown again.',
    ],

    'invalid' => 'That code is not correct.',
    'locked' => 'Too many incorrect codes. Two-factor sign-in is locked for an hour; try again later.',
    'expired' => 'That sign-in took too long. Please log in again.',

];

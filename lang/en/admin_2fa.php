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
    ],

    'recovery' => [
        'heading' => 'Save your recovery codes',
        'help' => 'Each code works once. Keep them somewhere safe — they will not be shown again.',
    ],

    'invalid' => 'That code is not correct.',
    'expired' => 'That sign-in took too long. Please log in again.',

];

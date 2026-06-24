<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

    'workshop_intake' => [
        'notification_email' => env('WORKSHOP_INTAKE_NOTIFICATION_EMAIL'),
        'mail_attachments_max_mb' => env('WORKSHOP_INTAKE_MAIL_ATTACHMENTS_MAX_MB', 20),
    ],

    'google_translate' => [
        'enabled' => env('GOOGLE_TRANSLATE_ENABLED', false),
        'mode' => env('GOOGLE_TRANSLATE_MODE', 'dry_run'),
        'project_id' => env('GOOGLE_TRANSLATE_PROJECT_ID'),
        'key' => env('GOOGLE_TRANSLATE_API_KEY'),
        'credentials_path' => env('GOOGLE_TRANSLATE_CREDENTIALS', env('GOOGLE_APPLICATION_CREDENTIALS')),
        'timeout' => env('GOOGLE_TRANSLATE_TIMEOUT', 10),
    ],
];

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

    'shipments' => [
        'sender' => [
            'name' => env('SHIPMENT_SENDER_NAME', 'GREGOR SWISS'),
            'address' => env('SHIPMENT_SENDER_ADDRESS', 'Milanowska 137'),
            'postal_code' => env('SHIPMENT_SENDER_POSTAL_CODE', '08-460'),
            'city' => env('SHIPMENT_SENDER_CITY', 'Sobolew'),
            'country' => env('SHIPMENT_SENDER_COUNTRY', 'PL'),
            'contact_name' => env('SHIPMENT_SENDER_CONTACT_NAME', 'GRZEGORZ PACIOREK'),
            'phone' => env('SHIPMENT_SENDER_PHONE', '579 152 665'),
            'email' => env('SHIPMENT_SENDER_EMAIL'),
        ],
    ],

    'dhl' => [
        'endpoint' => env('DHL_API_ENDPOINT', env('DHL24_WSDL')),
        'login' => env('DHL_API_LOGIN', env('DHL24_LOGIN', env('DHL24_USERNAME'))),
        'password' => env('DHL_API_PASSWORD', env('DHL24_PASSWORD')),
        'account_number' => env('DHL_ACCOUNT_NUMBER', env('DHL24_ACCOUNT_NUMBER', '2520734')),
        'default_service' => env('DHL_DEFAULT_SERVICE', env('DHL24_DEFAULT_SERVICE_TYPE', 'AH')),
        'test_mode' => env('DHL_TEST_MODE', env('DHL24_MODE', 'test') !== 'production'),
        'label_type' => env('DHL_LABEL_TYPE', env('DHL24_LABEL_TYPE', 'LBLP')),
        'drop_off_type' => env('DHL_DROP_OFF_TYPE', env('DHL24_DEFAULT_DROP_OFF_TYPE', 'REGULAR_PICKUP')),
    ],

    'dpd' => [
        'endpoint' => env('DPD_API_ENDPOINT'),
        'login' => env('DPD_API_LOGIN'),
        'password' => env('DPD_API_PASSWORD'),
        'account_number' => env('DPD_ACCOUNT_NUMBER'),
        'default_service' => env('DPD_DEFAULT_SERVICE', 'CLASSIC'),
        'test_mode' => env('DPD_TEST_MODE', true),
    ],

];

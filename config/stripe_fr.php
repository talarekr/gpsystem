<?php

return [
    'enabled' => env('STRIPE_FR_ENABLED', false),
    'mode' => env('STRIPE_FR_MODE', 'test'),
    'secret_key' => env('STRIPE_FR_SECRET_KEY'),
    'publishable_key' => env('STRIPE_FR_PUBLISHABLE_KEY'),
    'webhook_secret' => env('STRIPE_FR_WEBHOOK_SECRET'),
];

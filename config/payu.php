<?php

return [
    'env' => env('PAYU_ENV', 'sandbox'),
    'client_id' => env('PAYU_CLIENT_ID'),
    'client_secret' => env('PAYU_CLIENT_SECRET'),
    'merchant_pos_id' => env('PAYU_MERCHANT_POS_ID'),
    'second_key' => env('PAYU_SECOND_KEY'),
    'currency' => env('PAYU_CURRENCY', 'PLN'),
    'continue_url' => env('PAYU_CONTINUE_URL', env('APP_URL').'/zamowienie/payu/powrot'),
    'notify_url' => env('PAYU_NOTIFY_URL', env('APP_URL').'/payu/notify'),
    'timeout' => env('PAYU_TIMEOUT', 15),
    'base_urls' => [
        'production' => 'https://secure.payu.com',
        'sandbox' => 'https://secure.snd.payu.com',
    ],
];

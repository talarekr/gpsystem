<?php

return [
    'legacy_connection' => env('STOREFRONT_LEGACY_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
    'central_hosts' => array_values(array_filter(array_map('trim', explode(',', env('STOREFRONT_CENTRAL_HOSTS', 'staging.wystawczesc.pl,wystawczesc.pl,www.wystawczesc.pl,localhost,127.0.0.1'))))),
];

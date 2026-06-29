<?php

return [
    'publish_enabled' => env('MARKETPLACE_PUBLISH_ENABLED', false),
    'external_api_writes_enabled' => env('GPS_EXTERNAL_API_WRITES_ENABLED', false),
    'marketplace_publishing_enabled' => env('GPS_MARKETPLACE_PUBLISHING_ENABLED', false),
    'ebay_publishing_enabled' => env('GPS_EBAY_PUBLISHING_ENABLED', false),
    'allegro_publishing_enabled' => env('GPS_ALLEGRO_PUBLISHING_ENABLED', false),
    'ovoko_publishing_enabled' => env('GPS_OVOKO_PUBLISHING_ENABLED', false),
];

<?php

return [
    'publish_enabled' => env('MARKETPLACE_PUBLISH_ENABLED', false),
    'external_api_writes_enabled' => env('GPS_EXTERNAL_API_WRITES_ENABLED', false),
    'marketplace_publishing_enabled' => env('GPS_MARKETPLACE_PUBLISHING_ENABLED', false),
    'ebay_publishing_enabled' => env('GPS_EBAY_PUBLISHING_ENABLED', false),
    'ebay_description_revise_enabled' => env('GPS_EBAY_DESCRIPTION_REVISE_ENABLED', false),
    'allegro_publishing_enabled' => env('GPS_ALLEGRO_PUBLISHING_ENABLED', false),
    'ovoko_publishing_enabled' => env('GPS_OVOKO_PUBLISHING_ENABLED', false),
    'allegro_user_agent' => env('GPS_ALLEGRO_USER_AGENT', 'GPswiss/v1.0 (+https://gpswiss.pl/api-info)'),
    'allegro_max_images' => (int) env('MARKETPLACE_ALLEGRO_MAX_IMAGES', 16),
    'ebay_max_images' => (int) env('MARKETPLACE_EBAY_MAX_IMAGES', 24),
    'ovoko_max_images' => (int) env('MARKETPLACE_OVOKO_MAX_IMAGES', 10),
    'price_sync_on_part_save_enabled' => env('MARKETPLACE_PRICE_SYNC_ON_PART_SAVE_ENABLED', false),
    'price_sync_channels' => env('MARKETPLACE_PRICE_SYNC_CHANNELS', 'allegro,ovoko,ebay_de'),
];

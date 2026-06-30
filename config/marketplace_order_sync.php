<?php

return [
    'enabled' => (bool) env('GPS_MARKETPLACE_ORDER_SYNC_ENABLED', false),
    'lookback_days' => (int) env('GPS_MARKETPLACE_ORDER_SYNC_LOOKBACK_DAYS', 3),
    'channels' => env('GPS_MARKETPLACE_ORDER_SYNC_CHANNELS', 'allegro,ebay'),
];

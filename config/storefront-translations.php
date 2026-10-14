<?php

return [
    // Browser mode needs no queue infrastructure. Queue remains an explicit opt-in.
    'admin_runner' => env('GPSWISS_FR_ADMIN_RUNNER', 'browser'),
    // Hard-capped again by the incremental browser runner; no API is called on save.
    'new_products_max_records' => env('GPSWISS_FR_NEW_PRODUCTS_MAX_RECORDS', 100),
    'new_products_max_characters' => env('GPSWISS_FR_NEW_PRODUCTS_MAX_CHARACTERS', 50000),
    'fr_enabled' => env('GPSWISS_FR_TRANSLATIONS_ENABLED', false),
];

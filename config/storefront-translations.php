<?php

return [
    // Browser mode needs no queue infrastructure. Queue remains an explicit opt-in.
    'admin_runner' => env('GPSWISS_FR_ADMIN_RUNNER', 'browser'),
    'fr_enabled' => env('GPSWISS_FR_TRANSLATIONS_ENABLED', false),
];

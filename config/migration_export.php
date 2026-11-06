<?php

return [
    'root' => storage_path('app/private/migration-exports'),

    // Explicit production PHP CLI (>=8.3). FPM's PHP_BINARY is not a CLI fallback.
    // Without this configuration, use only the dedicated manual worker command.
    'php_cli' => env('GPSWISS_MIGRATION_PHP_CLI'),
    'snapshot_memory_mb' => (int) env('GPSWISS_MIGRATION_SNAPSHOT_MEMORY_MB', 1024),
];

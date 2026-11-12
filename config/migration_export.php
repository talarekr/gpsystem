<?php

return [
    'product_source_commit' => env('GPSWISS_PRODUCT_EXPORT_SOURCE_COMMIT'),
    'root' => storage_path('app/private/migration-exports'),

    // Browser v3 never launches CLI. These settings belong only to the optional legacy CLI mode.
    'php_cli' => env('GPSWISS_MIGRATION_PHP_CLI'),
    'snapshot_memory_mb' => (int) env('GPSWISS_MIGRATION_SNAPSHOT_MEMORY_MB', 1024),

    'browser_snapshot_rows' => 5,
    'photo_chunk_bytes' => 512 * 1024,
    'archive_payload_bytes' => 8 * 1024 * 1024,
];

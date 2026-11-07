<?php

use App\Services\MigrationExport\BrowserMigrationExport;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Standalone test worker: no production CLI entry point, databases, or provider requests.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
DB::listen(static function (): void {
    throw new RuntimeException('unexpected_source_query');
});
$directory = $argv[1];
$file = $directory.'/logical-snapshot/'.BrowserMigrationSnapshot::DRIFT_ATTEMPT.'/checkpoint.json';
$before = hash_file('sha256', $file);
$result = $app->make(BrowserMigrationExport::class)->snapshotTick($directory, BrowserMigrationSnapshot::DRIFT_ATTEMPT);
$engineResult = $app->make(BrowserMigrationSnapshot::class)->tick($directory, BrowserMigrationSnapshot::DRIFT_ATTEMPT);
fwrite(STDOUT, json_encode(['phase' => $result['phase'], 'peak' => memory_get_peak_usage(true), 'checkpoint_unchanged' => hash_equals($before, hash_file('sha256', $file)),
    'engine_result_bytes' => strlen(json_encode($engineResult)), 'exported_records' => $result['exported_records'], 'verification_processed' => $result['verification_processed']])."\n");

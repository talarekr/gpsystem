<?php

use App\Services\MigrationExport\AllegroSupplementDiagnostics;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

// Subprocess-only synthetic fatal: no application data, DB connection, HTTP or production filesystem.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
$diagnostic = new AllegroSupplementDiagnostics($argv[1], 'plan', ['parent_run_id' => 'synthetic']);
$diagnostic->arm();
$diagnostic->stage('persist_parent_index');
$allocations = [];
while (true) {
    $allocations[] = str_repeat('x', 1024 * 1024);
}

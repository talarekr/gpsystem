<?php

namespace App\Console\Commands;

use App\Services\Marketplace\BuildAllegroMappingsFromPartsService;
use Illuminate\Console\Command;

class BuildAllegroMappingsFromParts extends Command
{
    protected $signature = 'marketplace:build-allegro-mappings-from-parts {--dry-run : Preview without writing}';
    protected $description = 'Build safe Allegro marketplace mappings from parts.legacy_payload without external APIs.';

    public function handle(BuildAllegroMappingsFromPartsService $service): int
    {
        try { $summary = $service->run((bool) $this->option('dry-run')); }
        catch (\Throwable $exception) { $this->error($exception->getMessage()); return self::FAILURE; }
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}

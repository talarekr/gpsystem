<?php

namespace App\Console\Commands;

use App\Services\Marketplace\BuildEbayMappingsFromPartsService;
use Illuminate\Console\Command;

class BuildEbayMappingsFromParts extends Command
{
    protected $signature = 'marketplace:build-ebay-mappings-from-parts {--dry-run : Preview without writing}';
    protected $description = 'Build safe historical eBay marketplace mappings from parts.legacy_payload without external APIs.';

    public function handle(BuildEbayMappingsFromPartsService $service): int
    {
        try { $summary = $service->run((bool) $this->option('dry-run')); }
        catch (\Throwable $exception) { $this->error($exception->getMessage()); return self::FAILURE; }
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}

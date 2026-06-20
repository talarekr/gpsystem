<?php

namespace App\Console\Commands;

use App\Services\Marketplace\BuildOvokoMappingsFromPartsService;
use Illuminate\Console\Command;

class BuildOvokoMappingsFromParts extends Command
{
    protected $signature = 'marketplace:build-ovoko-mappings-from-parts {--dry-run : Preview without writing}';
    protected $description = 'Build safe Ovoko marketplace mappings from parts.legacy_payload without using CSV.';

    public function handle(BuildOvokoMappingsFromPartsService $service): int
    {
        try {
            $summary = $service->run((bool) $this->option('dry-run'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}

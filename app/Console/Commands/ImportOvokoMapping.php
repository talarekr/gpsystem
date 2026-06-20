<?php

namespace App\Console\Commands;

use App\Services\Marketplace\OvokoMappingImporter;
use Illuminate\Console\Command;

class ImportOvokoMapping extends Command
{
    protected $signature = 'marketplace:import-ovoko-mapping {--file= : Path to woo_ovoko_mapping.csv} {--dry-run : Preview without writing}';
    protected $description = 'Import legacy WooCommerce to Ovoko mapping into marketplace listings.';

    public function handle(OvokoMappingImporter $importer): int
    {
        $file = (string) $this->option('file');
        if ($file === '') {
            $this->error('Option --file is required.');
            return self::FAILURE;
        }
        $summary = $importer->import($file, (bool) $this->option('dry-run'));
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}

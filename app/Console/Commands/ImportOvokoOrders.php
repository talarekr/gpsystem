<?php

namespace App\Console\Commands;

use App\Services\Marketplace\OvokoOrdersImportDryRunService;
use Illuminate\Console\Command;

class ImportOvokoOrders extends Command
{
    protected $signature = 'marketplace:import-ovoko-orders {--from= : Start date Y-m-d} {--to= : End date Y-m-d} {--dry-run : Only report what would be imported}';
    protected $description = 'Dry-run import of Ovoko orders into the shared Laravel orders model.';

    public function handle(OvokoOrdersImportDryRunService $service): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Only --dry-run is supported. Live Ovoko order import was not added in this step.');
            return self::FAILURE;
        }

        $from = (string) ($this->option('from') ?? '');
        $to = $this->option('to') ? (string) $this->option('to') : now()->toDateString();
        $dateError = $service->validateDates($from, $to);
        if ($dateError !== null) {
            $this->error($dateError);
            return self::FAILURE;
        }

        $result = $service->run($from, $to);
        $this->line(json_encode(collect($result)->except('http_response_code')->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}

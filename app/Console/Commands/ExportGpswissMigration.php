<?php

namespace App\Console\Commands;

use App\Services\MigrationExport\GpswissMigrationExport;
use Illuminate\Console\Command;

final class ExportGpswissMigration extends Command
{
    protected $signature = 'gps:migration-export {--run= : Resume the same export} {--owner-id=0 : OwnerAdmin ID for private panel downloads}';

    protected $description = 'Create a private five-product sample and full catalog export without source writes or marketplace calls';

    public function handle(GpswissMigrationExport $exports): int
    {
        $owner = filter_var($this->option('owner-id'), FILTER_VALIDATE_INT);
        if ($owner === false || $owner < 0) {
            $this->error('invalid_owner_id');

            return self::FAILURE;
        }
        try {
            $state = $this->option('run') ? $exports->status($owner, (string) $this->option('run')) : $exports->start($owner);
            $this->line('run_id='.$state['run_id']);
            while (in_array($state['state'], ['photos', 'archive'], true)) {
                $state = $exports->tick($owner, $state['run_id']);
                $this->line(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if ($state['last_error'] || ($state['busy'] ?? false)) {
                    return self::FAILURE;
                }
            }
            if ($state['state'] !== 'completed') {
                return self::FAILURE;
            }
            foreach (['sample', 'full'] as $kind) {
                $this->line($exports->download($owner, $state['run_id'], $kind));
            }

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('export_failed_no_source_changes; resume a reported run_id if present');

            return self::FAILURE;
        }
    }
}

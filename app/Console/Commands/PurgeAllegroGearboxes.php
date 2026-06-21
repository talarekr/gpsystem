<?php

namespace App\Console\Commands;

use App\Services\Tools\PurgeAllegroGearboxesService;
use Illuminate\Console\Command;

class PurgeAllegroGearboxes extends Command
{
    protected $signature = 'parts:purge-allegro-gearboxes {--dry-run : Preview the purge plan without changing data} {--confirm= : Required confirmation for live purge}';

    protected $description = 'Safely purge confirmed Allegro Gearboxes imported parts and local dependencies from the database.';

    public function handle(PurgeAllegroGearboxesService $service): int
    {
        try {
            $result = $this->option('dry-run')
                ? $service->dryRun()
                : $service->live((string) $this->option('confirm'));

            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}

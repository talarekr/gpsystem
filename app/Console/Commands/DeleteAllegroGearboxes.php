<?php

namespace App\Console\Commands;

use App\Services\Tools\DeleteAllegroGearboxesService;
use Illuminate\Console\Command;

class DeleteAllegroGearboxes extends Command
{
    protected $signature = 'parts:delete-allegro-gearboxes {--dry-run : Preview without writing} {--confirm= : Required confirmation token for live run}';
    protected $description = 'Safely archive Allegro Gearboxes imported parts in the Laravel store only.';

    public function handle(DeleteAllegroGearboxesService $service): int
    {
        try {
            $summary = $this->option('dry-run')
                ? $service->dryRun()
                : $service->live((string) $this->option('confirm'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}

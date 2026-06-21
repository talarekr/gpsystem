<?php

namespace App\Console\Commands;

use App\Services\Tools\MarkGpsGmailPartsToListService;
use Illuminate\Console\Command;

class MarkGpsGmailPartsToList extends Command
{
    protected $signature = 'parts:mark-gps-gmail-to-list {--dry-run : Preview without changing data} {--confirm= : Required confirmation for live run}';

    protected $description = 'Mark parts whose SKU contains gps-gmail as needing listing.';

    public function handle(MarkGpsGmailPartsToListService $service): int
    {
        try {
            $result = $this->option('dry-run')
                ? $service->dryRun()
                : $service->live((string) $this->option('confirm'));

            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\MarketplaceSyncLog;
use Illuminate\Console\Command;

class PruneMarketplaceSyncLogs extends Command
{
    protected $signature = 'marketplace:prune-api-logs {--days=60 : Keep logs from the last N days}';

    protected $description = 'Delete old lightweight marketplace/API integration logs.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = MarketplaceSyncLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} marketplace/API logs older than {$days} days.");

        return self::SUCCESS;
    }
}

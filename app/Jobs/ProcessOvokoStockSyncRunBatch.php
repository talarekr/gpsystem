<?php

namespace App\Jobs;

use App\Models\OvokoStockSyncRun;
use App\Services\Marketplace\OvokoStockSyncRunProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOvokoStockSyncRunBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $runId) {}

    public function handle(OvokoStockSyncRunProcessor $processor): void
    {
        $run = OvokoStockSyncRun::query()->find($this->runId);
        if (! $run || ! in_array($run->status, ['queued', 'running'], true)) return;

        $summary = $processor->tick($run);
        if (($summary['ok'] ?? false) && ($summary['has_more'] ?? false) && ! ($summary['locked'] ?? false)) {
            self::dispatch((int) $run->id);
        }
    }
}

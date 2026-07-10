<?php

namespace App\Jobs;

use App\Services\Marketplace\Ovoko\OvokoCarModelSyncRunnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncOvokoCarModelsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $runId) {}

    public function handle(OvokoCarModelSyncRunnerService $service): void
    {
        $service->runNextBatch($this->runId);
    }
}

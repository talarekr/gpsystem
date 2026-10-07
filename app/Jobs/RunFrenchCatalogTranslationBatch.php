<?php

namespace App\Jobs;

use App\Services\Storefront\FrenchCatalogTranslationAdminRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** One bounded catalog batch. Record retry delays/checkpoints belong to the runner. */
class RunFrenchCatalogTranslationBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 540;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId)
    {
        $this->onConnection('storefront-translations');
        $this->onQueue('storefront-fr-translations');
        $this->afterCommit();
    }

    public function handle(FrenchCatalogTranslationAdminRunner $runner): void
    {
        try {
            $runner->runBatch($this->runId);
        } catch (Throwable) {
            // Raw exceptions may contain provider credentials or database configuration.
            throw new \RuntimeException('French catalog translation batch interrupted.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(FrenchCatalogTranslationAdminRunner::class)->failed($this->runId);
    }
}

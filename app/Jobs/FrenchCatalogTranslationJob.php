<?php

namespace App\Jobs;

use App\Services\Storefront\FrenchCatalogTranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class FrenchCatalogTranslationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 540;

    public bool $failOnTimeout = true;

    public function __construct(public int $recordId, public array $options = [])
    {
        $this->onConnection('storefront-translations');
        $this->onQueue('storefront-fr-translations');
        $this->afterCommit();
    }

    abstract protected function catalogType(): string;

    public function backoff(): array
    {
        return FrenchCatalogTranslationService::BACKOFF;
    }

    public function handle(FrenchCatalogTranslationService $service): void
    {
        $options = $this->options;
        if ($this->attempts() > 1) {
            $options['include_failed'] = true;
        }
        $result = $service->translate($this->catalogType(), $this->recordId, $options);
        if ($result['reason'] === 'busy') {
            $this->release(30);
        } elseif ($result['retryable']) {
            // The persisted error and failed_jobs exception contain only a controlled code.
            throw new \RuntimeException('French catalog translation transient failure: '.$result['reason']);
        }
    }
}

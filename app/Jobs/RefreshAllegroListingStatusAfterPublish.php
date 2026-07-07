<?php

namespace App\Jobs;

use App\Models\MarketplaceListing;
use App\Services\Marketplace\AllegroListingStatusRefreshService;
use App\Services\Marketplace\ApiIntegrationLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshAllegroListingStatusAfterPublish implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 5;
    public const RETRY_DELAY_MINUTES = 2;

    public function __construct(public int $listingId, public int $attempt = 1) {}

    public function handle(AllegroListingStatusRefreshService $service, ApiIntegrationLogger $logger): void
    {
        $listing = MarketplaceListing::query()->find($this->listingId);
        if (! $listing || $listing->marketplace !== 'allegro') {
            return;
        }

        $result = $service->refresh($listing, null, true);
        $publicationStatus = strtoupper((string) data_get($result, 'api.publication_status', ''));
        $isActiveWithStock = (bool) data_get($result, 'api.is_active_with_stock', false);
        $shouldRetry = ! $isActiveWithStock && $publicationStatus !== 'ENDED' && $this->attempt < self::MAX_ATTEMPTS;

        $logger->success('allegro', 'allegro_post_publish_status_refresh_executed', 'Allegro post-publish status refresh executed.', [
            'marketplace_listing_id' => $listing->id,
            'part_id' => $listing->part_id,
            'external_id' => $listing->external_offer_id ?: $listing->external_listing_id,
            'attempt' => $this->attempt,
            'max_attempts' => self::MAX_ATTEMPTS,
            'will_retry' => $shouldRetry,
            'api' => $result['api'] ?? null,
            'changes' => $result['changes'] ?? [],
        ]);

        if ($shouldRetry) {
            self::dispatch($listing->id, $this->attempt + 1)->delay(now()->addMinutes(self::RETRY_DELAY_MINUTES));
            $logger->success('allegro', 'allegro_post_publish_status_refresh_scheduled', 'Allegro post-publish status refresh retry scheduled.', [
                'marketplace_listing_id' => $listing->id,
                'part_id' => $listing->part_id,
                'external_id' => $listing->external_offer_id ?: $listing->external_listing_id,
                'attempt' => $this->attempt + 1,
                'delay_minutes' => self::RETRY_DELAY_MINUTES,
                'max_attempts' => self::MAX_ATTEMPTS,
            ]);
        }
    }
}

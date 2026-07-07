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
            $logger->error('allegro', 'allegro_post_publish_status_refresh_skipped', 'Allegro post-publish status refresh skipped: listing not found or not Allegro.', [
                'marketplace_listing_id' => $this->listingId,
                'listing_id' => $this->listingId,
                'attempt' => $this->attempt,
                'skip_reason' => $listing ? 'not_allegro_listing' : 'listing_not_found',
            ]);
            return;
        }

        $before = ['status' => $listing->status, 'last_api_status' => $listing->last_api_status, 'last_error' => $listing->last_error];
        $result = $service->refresh($listing, null, true);
        $publicationStatus = strtoupper((string) data_get($result, 'api.publication_status', ''));
        $isActiveWithStock = (bool) data_get($result, 'api.is_active_with_stock', false);
        $shouldRetry = ! $isActiveWithStock && $publicationStatus !== 'ENDED' && $this->attempt < self::MAX_ATTEMPTS;
        $offerId = $listing->external_offer_id ?: $listing->external_listing_id;

        $logger->success('allegro', 'allegro_post_publish_status_refresh_executed', 'Allegro post-publish status refresh executed.', [
            'marketplace_listing_id' => $listing->id,
            'listing_id' => $listing->id,
            'part_id' => $listing->part_id,
            'external_id' => $offerId,
            'offer_id' => $offerId,
            'attempt' => $this->attempt,
            'max_attempts' => self::MAX_ATTEMPTS,
            'will_retry' => $shouldRetry,
            'job_status' => ($result['ok'] ?? false) ? 'executed' : 'api_error',
            'api_publication_status' => data_get($result, 'api.publication_status'),
            'before_local_listing_status' => $before['status'],
            'after_local_listing_status' => data_get($result, 'after.status'),
            'api' => $result['api'] ?? null,
            'changes' => $result['changes'] ?? [],
        ]);

        if ($shouldRetry) {
            self::dispatch($listing->id, $this->attempt + 1)->delay(now()->addMinutes(self::RETRY_DELAY_MINUTES));
            $logger->success('allegro', 'allegro_post_publish_status_refresh_scheduled', 'Allegro post-publish status refresh retry scheduled.', [
                'post_publish_refresh_scheduled' => true,
                'marketplace_listing_id' => $listing->id,
                'listing_id' => $listing->id,
                'part_id' => $listing->part_id,
                'external_id' => $offerId,
                'offer_id' => $offerId,
                'attempt' => $this->attempt + 1,
                'delay_minutes' => self::RETRY_DELAY_MINUTES,
                'delay_seconds' => self::RETRY_DELAY_MINUTES * 60,
                'max_attempts' => self::MAX_ATTEMPTS,
                'queue_connection' => config('queue.default'),
            ]);
        }
    }
}

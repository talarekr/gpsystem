<?php

namespace App\Services\Marketplace\Publishing;

use App\Jobs\RefreshAllegroListingStatusAfterPublish;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\AllegroListingStatusRefreshService;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use App\Services\Marketplace\MarketplacePublishGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use App\Services\Marketplace\OvokoStaleListingService;

abstract class BaseMarketplacePublishAdapter implements MarketplacePublishAdapterInterface
{
    public function __construct(protected readonly MarketplaceListingReadinessService $readinessService, protected readonly MarketplacePublishGate $gate, protected readonly ApiIntegrationLogger $logger) {}

    abstract protected function channel(): string;
    abstract protected function marketplace(): string;
    abstract protected function accountCode(): string;
    abstract protected function performLivePublish(Part $part, array $readiness, array $payload, ?MarketplaceAccount $account): array;

    public function preview(Part $part): MarketplacePublishPreviewResult
    {
        $readiness = $this->readinessService->checkPartReadiness($part, $this->channel());
        $gate = $this->gate->decision($this->channel());
        return new MarketplacePublishPreviewResult($this->channel(), [
            'channel' => $this->channel(), 'marketplace' => $this->marketplace(),
            'success' => (bool) ($readiness['can_publish_later'] ?? false), 'blocked' => ! (bool) ($readiness['can_publish_later'] ?? false),
            'errors' => $readiness['blockers'] ?? [], 'warnings' => $readiness['warnings'] ?? [],
            'publish_gate' => $gate, 'external_listing_id' => null, 'write' => false,
            'payload_preview_safe' => $readiness['prepared_payload_preview_safe'] ?? [], 'readiness' => $readiness,
        ]);
    }

    public function publish(Part $part, MarketplacePublishCommand $command): MarketplacePublishResult
    {
        if ($this->marketplace() === 'allegro') {
            $lock = Cache::lock($this->publishLockKey($part), 180);
            if (! $lock->get()) return $this->blocked('allegro_publish_already_in_progress', ['status' => 'allegro_publish_already_in_progress']);

            try {
                return $this->publishWithoutLock($part, $command);
            } finally {
                optional($lock)->release();
            }
        }

        return $this->publishWithoutLock($part, $command);
    }

    private function publishWithoutLock(Part $part, MarketplacePublishCommand $command): MarketplacePublishResult
    {
        $gate = $this->gate->decision($this->channel());
        if ($command->dryRun || ! $command->confirm || ! $gate['allowed']) return $this->blocked('publish_blocked_by_flags', ['publish_gate' => $gate]);
        if ($existing = $this->activeListing($part)) return $this->blocked('duplicate_guard_existing_listing', ['status' => 'skipped_existing_listing', 'external_listing_id' => $existing->external_listing_id ?: $existing->external_offer_id, 'warnings' => ['Istnieje lokalny aktywny listing marketplace; nie wykonano duplikującego publish.']]);

        $readiness = $this->readinessService->checkPartReadiness($part, $this->channel());
        if (! (bool) ($readiness['can_publish_later'] ?? false)) return new MarketplacePublishResult($this->channel(), ['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => false, 'status' => 'blocked_readiness', 'errors' => $readiness['blockers'] ?? [], 'warnings' => $readiness['warnings'] ?? [], 'write' => false]);

        $payload = $readiness['prepared_payload_preview_safe'] ?? [];
        $imageSelection = app(\App\Services\Marketplace\MarketplaceImageSelectionService::class)->selectForPart($part, 0, true, $this->channel());
        $payload['image_urls'] = $imageSelection['urls'];
        $payload['marketplace_image_diagnostics'] = $imageSelection['diagnostics'];
        $account = MarketplaceAccount::query()->where('code', $this->accountCode())->first();
        $started = microtime(true);
        try { $result = $this->performLivePublish($part, $readiness, $payload, $account); }
        catch (\Throwable $e) { $result = ['ok' => false, 'status' => 'exception', 'error' => $e->getMessage(), 'exception' => $e::class]; }
        $duration = (int) round((microtime(true) - $started) * 1000);

        if (! ($result['ok'] ?? false)) {
            $this->logger->error($this->marketplace(), $result['action'] ?? 'publish', $result['error'] ?? 'Marketplace publish failed.', ['part_id' => $part->id, 'http_status' => $result['http_status'] ?? null, 'duration_ms' => $duration, 'request' => $result['request_summary'] ?? $this->requestSummary($payload), 'response' => $result['response_summary'] ?? $result['json'] ?? null, 'request_id' => $result['request_id'] ?? null]);
            return new MarketplacePublishResult($this->channel(), ['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => false, 'status' => $result['status'] ?? 'api_error', 'errors' => [$result['ui_error'] ?? 'marketplace_api_error'], 'warnings' => [], 'write' => false]);
        }

        $resolvedSku = $result['resolved_sku'] ?? $result['external_inventory_id'] ?? ($payload['sku'] ?? $part->sku);
        $requestSummary = $result['request_summary'] ?? $this->requestSummary($payload);

        $listing = filled($result['marketplace_listing_id'] ?? null) ? MarketplaceListing::query()->find($result['marketplace_listing_id']) : null;
        if (! $listing) {
            $listing = MarketplaceListing::query()->create(['marketplace' => $this->marketplace(), 'marketplace_account_id' => $account?->id, 'part_id' => $part->id, 'external_offer_id' => $result['external_offer_id'] ?? $result['offer_id'] ?? null, 'external_listing_id' => $result['external_listing_id'] ?? $result['listing_id'] ?? null, 'external_inventory_id' => $result['external_inventory_id'] ?? $resolvedSku, 'sku' => $resolvedSku, 'title' => $payload['title'] ?? $part->name, 'price' => $readiness['marketplace_price'] ?? $part->price, 'quantity' => $part->quantity, 'currency' => $readiness['currency'] ?? 'PLN', 'status' => $result['listing_status'] ?? 'published', 'sync_status' => 'published', 'match_status' => 'matched', 'match_confidence' => 100, 'url' => $result['url'] ?? null, 'raw_payload' => ['request_summary' => $requestSummary, 'response_summary' => $result['response_summary'] ?? []], 'last_synced_at' => now()]);
        }
        $this->logger->success($this->marketplace(), $result['action'] ?? 'publish', 'Marketplace publish API call completed.', array_merge($result['log_context'] ?? [], ['marketplace_listing_id' => $listing->id, 'listing_id' => $listing->id, 'part_id' => $part->id, 'channel' => $this->channel(), 'http_status' => $result['http_status'] ?? null, 'duration_ms' => $duration, 'external_id' => $listing->external_listing_id ?: $listing->external_offer_id, 'allegro_offer_id' => $this->marketplace() === 'allegro' ? ($listing->external_offer_id ?: $listing->external_listing_id) : ($result['allegro_offer_id'] ?? null), 'saved_url' => $listing->url, 'local_listing_status' => $listing->status, 'operation_location' => $result['operation_location'] ?? null, 'async' => (bool) ($result['async'] ?? false), 'request_id' => $result['request_id'] ?? null, 'request' => $requestSummary, 'response' => $result['response_summary'] ?? [], 'stored_listing_external_id' => $listing->external_offer_id ?: $listing->external_listing_id, 'stored_listing_url' => $listing->url]));
        if ($this->marketplace() === 'ovoko') {
            $this->logger->success('ovoko', filled($listing->url) ? 'ovoko_listing_url_resolved' : 'missing_shop_url', filled($listing->url) ? 'Ovoko listing URL stored after publish.' : 'Ovoko crm/importPart returned no public listing URL; use read-only URL diagnostic/backfill.', ['marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'external_id' => $listing->external_offer_id ?: $listing->external_listing_id, 'ovoko_part_id' => $listing->external_offer_id ?: $listing->external_listing_id, 'ovoko_listing_url' => $listing->url, 'ovoko_listing_url_source' => filled($listing->url) ? ($result['response_summary']['ovoko_listing_url_source'] ?? $result['response_summary']['ovoko_shop_url_source'] ?? 'unknown') : null, 'response' => $result['response_summary'] ?? [], 'stored_listing_url' => $listing->url]);
        }
        if ($this->marketplace() === 'allegro' && filled($listing->external_offer_id ?: $listing->external_listing_id)) {
            $immediateRefresh = $this->attemptImmediateAllegroPostPublishRefresh($listing, $part);
            $shouldScheduleRetry = ! (bool) data_get($immediateRefresh, 'api.is_active_with_stock', false);

            if ($shouldScheduleRetry) {
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($listing, $part): void {
                    RefreshAllegroListingStatusAfterPublish::dispatch($listing->id)->delay(now()->addMinutes(RefreshAllegroListingStatusAfterPublish::RETRY_DELAY_MINUTES));
                    $offerId = $listing->external_offer_id ?: $listing->external_listing_id;
                    $this->logger->success('allegro', 'allegro_post_publish_status_refresh_scheduled', 'Allegro post-publish status refresh scheduled.', [
                        'post_publish_refresh_scheduled' => true,
                        'marketplace_listing_id' => $listing->id,
                        'listing_id' => $listing->id,
                        'part_id' => $part->id,
                        'external_id' => $offerId,
                        'offer_id' => $offerId,
                        'attempt' => 1,
                        'delay_minutes' => RefreshAllegroListingStatusAfterPublish::RETRY_DELAY_MINUTES,
                        'delay_seconds' => RefreshAllegroListingStatusAfterPublish::RETRY_DELAY_MINUTES * 60,
                        'max_attempts' => RefreshAllegroListingStatusAfterPublish::MAX_ATTEMPTS,
                        'queue_connection' => config('queue.default'),
                    ]);
                });
            }
        }
        return new MarketplacePublishResult($this->channel(), ['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => true, 'status' => $listing->status, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id, 'write' => true, 'listing_id' => $listing->id, 'message' => $result['user_message'] ?? null]);
    }

    private function attemptImmediateAllegroPostPublishRefresh(MarketplaceListing $listing, Part $part): array
    {
        $offerId = $listing->external_offer_id ?: $listing->external_listing_id;
        $beforeStatus = $listing->status;
        $result = app(AllegroListingStatusRefreshService::class)->refresh($listing, $offerId, true);
        $listing->refresh();
        $afterStatus = $listing->status;
        $isActiveWithStock = (bool) data_get($result, 'api.is_active_with_stock', false);
        $delayedRetryScheduled = ! $isActiveWithStock;

        $this->logger->success('allegro', 'immediate_post_publish_refresh_attempted', 'Immediate read-only Allegro post-publish status refresh attempted.', [
            'immediate_post_publish_refresh_attempted' => true,
            'marketplace_listing_id' => $listing->id,
            'listing_id' => $listing->id,
            'part_id' => $part->id,
            'external_id' => $offerId,
            'offer_id' => $offerId,
            'api_publication_status' => data_get($result, 'api.publication_status'),
            'api_stock_available' => data_get($result, 'api.stock_available'),
            'api_is_active_with_stock' => $isActiveWithStock,
            'before_local_listing_status' => $beforeStatus,
            'after_local_listing_status' => $afterStatus,
            'delayed_retry_scheduled' => $delayedRetryScheduled,
            'post_publish_refresh_scheduled' => $delayedRetryScheduled,
            'job_status' => ($result['ok'] ?? false) ? 'executed' : 'api_error',
            'api' => $result['api'] ?? null,
            'changes' => $result['changes'] ?? [],
            'read_only' => true,
        ]);

        return $result;
    }

    protected function blocked(string $reason, array $extra = []): MarketplacePublishResult { return new MarketplacePublishResult($this->channel(), array_merge(['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => false, 'status' => 'blocked', 'blocked' => true, 'errors' => [$reason], 'warnings' => [], 'write' => false], $extra)); }
    protected function activeListing(Part $part): ?MarketplaceListing { if (! Schema::hasTable('marketplace_listings')) return null; $query = MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', $this->marketplace())->where(function ($q) { $q->whereNotNull('external_listing_id')->orWhereNotNull('external_offer_id'); })->where(function ($q) { $q->whereNull('external_listing_id')->orWhere('external_listing_id', 'not like', 'GPSW-%'); })->where(function ($q) { $q->whereNull('external_offer_id')->orWhere('external_offer_id', 'not like', 'GPSW-%'); }); if (str_starts_with($this->marketplace(), 'ebay')) return $query->whereIn('status', ['published','active','ACTIVE','live'])->whereNotIn('last_api_status', ['ended','failed','deleted','archived','not_found','inactive','unavailable','error','ENDED','FAILED','DELETED','ARCHIVED','NOT_FOUND','INACTIVE','UNAVAILABLE','ERROR'])->whereNull('not_seen_in_active_api_at')->get()->first(fn (MarketplaceListing $listing): bool => ! $this->ebayEndDateIsPast($listing)); return $query->whereNotIn('status', ['ended','failed','deleted','archived','cancelled','historical','stale','unlinked','ENDED','FAILED','DELETED','ARCHIVED','CANCELLED','HISTORICAL','STALE','UNLINKED'])->whereNotIn('last_api_status', ['ended','failed','deleted','archived','not_found','ENDED','FAILED','DELETED','ARCHIVED','NOT_FOUND'])->get()->first(fn (MarketplaceListing $listing): bool => ! app(OvokoStaleListingService::class)->ignoredForPublish($listing)); }
    protected function ebayEndDateIsPast(MarketplaceListing $listing): bool { $endDate = data_get($listing->raw_payload, 'itemEndDate') ?? data_get($listing->raw_payload, 'item_end_date') ?? data_get($listing->raw_payload, 'api.end_date') ?? data_get($listing->raw_payload, 'response_summary.itemEndDate'); return filled($endDate) && strtotime((string) $endDate) !== false && strtotime((string) $endDate) < now()->timestamp; }
    protected function publishLockKey(Part $part): string { return 'marketplace_publish:'.$this->marketplace().':'.$part->id; }
    protected function requestSummary(array $payload): array { return ['keys' => array_keys($payload), 'sku' => $payload['sku'] ?? null, 'title_present' => filled($payload['title'] ?? null), 'category_id' => $payload['category_id'] ?? null, 'images_count' => count((array) ($payload['image_urls'] ?? [])), 'price' => $payload['price_eur'] ?? $payload['price_pln'] ?? null, 'quantity' => $payload['quantity'] ?? null, 'marketplace_images' => $payload['marketplace_image_diagnostics'] ?? null]; }
}

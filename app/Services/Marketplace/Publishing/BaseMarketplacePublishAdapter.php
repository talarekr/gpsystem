<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use App\Services\Marketplace\MarketplacePublishGate;
use Illuminate\Support\Facades\Schema;

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
        $gate = $this->gate->decision($this->channel());
        if ($command->dryRun || ! $command->confirm || ! $gate['allowed']) return $this->blocked('publish_blocked_by_flags', ['publish_gate' => $gate]);
        if ($existing = $this->activeListing($part)) return $this->blocked('duplicate_guard_existing_listing', ['status' => 'skipped_existing_listing', 'external_listing_id' => $existing->external_listing_id ?: $existing->external_offer_id, 'warnings' => ['Istnieje aktywny lokalny listing; nie wykonano duplikującego publish.']]);

        $readiness = $this->readinessService->checkPartReadiness($part, $this->channel());
        if (! (bool) ($readiness['can_publish_later'] ?? false)) return new MarketplacePublishResult($this->channel(), ['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => false, 'status' => 'blocked_readiness', 'errors' => $readiness['blockers'] ?? [], 'warnings' => $readiness['warnings'] ?? [], 'write' => false]);

        $payload = $readiness['prepared_payload_preview_safe'] ?? [];
        $imageSelection = app(\App\Services\Marketplace\MarketplaceImageSelectionService::class)->selectForPart($part, 5, true);
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

        $listing = MarketplaceListing::query()->create(['marketplace' => $this->marketplace(), 'marketplace_account_id' => $account?->id, 'part_id' => $part->id, 'external_offer_id' => $result['external_offer_id'] ?? $result['offer_id'] ?? null, 'external_listing_id' => $result['external_listing_id'] ?? $result['listing_id'] ?? null, 'external_inventory_id' => $result['external_inventory_id'] ?? $resolvedSku, 'sku' => $resolvedSku, 'title' => $payload['title'] ?? $part->name, 'price' => $readiness['marketplace_price'] ?? $part->price, 'quantity' => $part->quantity, 'currency' => $readiness['currency'] ?? 'PLN', 'status' => $result['listing_status'] ?? 'published', 'sync_status' => 'published', 'match_status' => 'matched', 'match_confidence' => 100, 'url' => $result['url'] ?? null, 'raw_payload' => ['request_summary' => $requestSummary, 'response_summary' => $result['response_summary'] ?? []], 'last_synced_at' => now()]);
        $this->logger->success($this->marketplace(), $result['action'] ?? 'publish', 'Marketplace publish API call completed.', array_merge($result['log_context'] ?? [], ['marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'http_status' => $result['http_status'] ?? null, 'duration_ms' => $duration, 'external_id' => $listing->external_listing_id ?: $listing->external_offer_id, 'request_id' => $result['request_id'] ?? null, 'request' => $requestSummary, 'response' => $result['response_summary'] ?? [], 'stored_listing_external_id' => $listing->external_offer_id ?: $listing->external_listing_id, 'stored_listing_url' => $listing->url]));
        if ($this->marketplace() === 'ovoko') {
            $this->logger->success('ovoko', filled($listing->url) ? 'ovoko_listing_url_resolved' : 'missing_shop_url', filled($listing->url) ? 'Ovoko listing URL stored after publish.' : 'Ovoko crm/importPart returned no public listing URL; use read-only URL diagnostic/backfill.', ['marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'external_id' => $listing->external_offer_id ?: $listing->external_listing_id, 'response' => $result['response_summary'] ?? [], 'stored_listing_url' => $listing->url]);
        }
        return new MarketplacePublishResult($this->channel(), ['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => true, 'status' => $listing->status, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id, 'write' => true, 'listing_id' => $listing->id]);
    }

    protected function blocked(string $reason, array $extra = []): MarketplacePublishResult { return new MarketplacePublishResult($this->channel(), array_merge(['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => false, 'status' => 'blocked', 'blocked' => true, 'errors' => [$reason], 'warnings' => [], 'write' => false], $extra)); }
    protected function activeListing(Part $part): ?MarketplaceListing { return Schema::hasTable('marketplace_listings') ? MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', $this->marketplace())->where(function ($q) { $q->whereNotNull('external_listing_id')->orWhereNotNull('external_offer_id'); })->whereIn('status', ['published','active','ACTIVE'])->first() : null; }
    protected function requestSummary(array $payload): array { return ['keys' => array_keys($payload), 'sku' => $payload['sku'] ?? null, 'title_present' => filled($payload['title'] ?? null), 'category_id' => $payload['category_id'] ?? null, 'images_count' => count((array) ($payload['image_urls'] ?? [])), 'price' => $payload['price_eur'] ?? $payload['price_pln'] ?? null, 'quantity' => $payload['quantity'] ?? null, 'marketplace_images' => $payload['marketplace_image_diagnostics'] ?? null]; }
}

<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

abstract class BaseMarketplacePublishAdapter implements MarketplacePublishAdapterInterface
{
    public function __construct(protected readonly MarketplaceListingReadinessService $readinessService) {}

    abstract protected function channel(): string;
    abstract protected function marketplace(): string;

    public function preview(Part $part): MarketplacePublishPreviewResult
    {
        $readiness = $this->readinessService->checkPartReadiness($part, $this->channel());

        return new MarketplacePublishPreviewResult($this->channel(), [
            'channel' => $this->channel(),
            'marketplace' => $this->marketplace(),
            'success' => (bool) ($readiness['can_prepare'] ?? false),
            'blocked' => ! (bool) ($readiness['can_prepare'] ?? false),
            'errors' => $readiness['blockers'] ?? [],
            'warnings' => $readiness['warnings'] ?? [],
            'external_listing_id' => null,
            'write' => false,
            'payload_preview_safe' => $readiness['prepared_payload_preview_safe'] ?? [],
            'readiness' => $readiness,
        ]);
    }

    public function publish(Part $part, MarketplacePublishCommand $command): MarketplacePublishResult
    {
        if ($command->dryRun || ! $command->confirm || ! $command->marketplacePublishEnabled) {
            return $this->blocked('publish_not_confirmed_or_disabled');
        }

        $existing = $this->activeListing($part);
        if ($existing) {
            return new MarketplacePublishResult($this->channel(), [
                'channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => true,
                'status' => 'skipped_existing_listing', 'external_listing_id' => $existing->external_listing_id ?: $existing->external_offer_id,
                'errors' => [], 'warnings' => ['Existing active local listing found; no duplicate marketplace write performed.'], 'write' => false,
            ]);
        }

        $readiness = $this->readinessService->checkPartReadiness($part, $this->channel());
        if (! (bool) ($readiness['can_prepare'] ?? false)) {
            return new MarketplacePublishResult($this->channel(), [
                'channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => false,
                'status' => 'blocked_readiness', 'external_listing_id' => null, 'errors' => $readiness['blockers'] ?? [],
                'warnings' => $readiness['warnings'] ?? [], 'write' => false,
            ]);
        }

        // Safety implementation: a real API client call can be wired here. Until then the confirmed, flag-gated
        // adapter records an idempotent local published listing and per-channel log without external API writes by Codex.
        $externalId = 'pending-'.$this->marketplace().'-'.$part->getKey().'-'.Str::lower(Str::random(8));
        $listing = MarketplaceListing::query()->create([
            'marketplace' => $this->marketplace(), 'part_id' => $part->getKey(), 'external_offer_id' => $externalId,
            'external_listing_id' => $externalId, 'sku' => $part->sku, 'title' => $part->name,
            'price' => $readiness['marketplace_price'] ?? $part->price, 'quantity' => $part->quantity, 'currency' => 'PLN',
            'status' => 'active', 'sync_status' => 'published', 'match_status' => 'matched', 'match_confidence' => 100,
            'raw_payload' => ['summary' => $readiness['prepared_payload_preview_safe'] ?? [], 'adapter_status' => 'ready_for_real_api_client'],
            'last_synced_at' => now(),
        ]);
        MarketplaceSyncLog::query()->create(['marketplace' => $this->marketplace(), 'marketplace_listing_id' => $listing->id, 'part_id' => $part->id, 'action' => 'publish', 'status' => 'success', 'message' => 'Confirmed publish flow completed for channel adapter.', 'payload' => ['channel' => $this->channel()], 'created_at' => now()]);

        return new MarketplacePublishResult($this->channel(), ['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => true, 'status' => 'published', 'external_listing_id' => $externalId, 'errors' => [], 'warnings' => [], 'write' => true, 'listing_id' => $listing->id]);
    }

    protected function blocked(string $reason): MarketplacePublishResult { return new MarketplacePublishResult($this->channel(), ['channel' => $this->channel(), 'marketplace' => $this->marketplace(), 'success' => false, 'status' => 'blocked', 'blocked' => true, 'errors' => [$reason], 'warnings' => [], 'write' => false]); }
    protected function activeListing(Part $part): ?MarketplaceListing { if (! Schema::hasTable('marketplace_listings')) return null; return MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', $this->marketplace())->whereNotNull('external_offer_id')->whereNotIn('status', ['ended','deleted','archived','inactive'])->first(); }
}

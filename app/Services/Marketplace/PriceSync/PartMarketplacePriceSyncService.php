<?php

namespace App\Services\Marketplace\PriceSync;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;

class PartMarketplacePriceSyncService
{
    /** @return array<string, mixed> */
    public function handlePartSaved(Part $part): array
    {
        if (! config('marketplace.price_sync_on_part_save_enabled', false)) {
            return ['triggered' => false, 'reason' => 'feature_flag_disabled'];
        }

        $changedPriceFields = collect(['price', 'allegro_price', 'ovoko_price', 'ebay_price'])
            ->filter(fn (string $field): bool => $part->wasChanged($field))
            ->values()
            ->all();

        if ($changedPriceFields === []) {
            return ['triggered' => false, 'reason' => 'no_price_field_changed'];
        }

        $channels = $this->enabledChannels();
        $results = [];

        foreach ($channels as $channel) {
            $results[] = $this->planChannel($part, $channel, $changedPriceFields);
        }

        return [
            'triggered' => true,
            'marketplace_write' => false,
            'changed_price_fields' => $changedPriceFields,
            'channels' => $channels,
            'results' => $results,
        ];
    }

    /** @return array<int, string> */
    public function enabledChannels(): array
    {
        $configured = (string) config('marketplace.price_sync_channels', 'allegro,ovoko,ebay_de');

        return collect(explode(',', $configured))
            ->map(fn (string $channel): string => trim($channel))
            ->filter(fn (string $channel): bool => in_array($channel, ['allegro', 'ovoko', 'ebay_de'], true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function diagnostics(?Part $part = null): array
    {
        return [
            'enabled' => (bool) config('marketplace.price_sync_on_part_save_enabled', false),
            'channels' => $this->enabledChannels(),
            'marketplace_write' => false,
            'live_api_tests' => false,
            'mutable_offer_fields' => ['price_only_planned'],
            'protected_offer_fields' => ['quantity', 'status', 'publication', 'description', 'photos', 'category'],
            'price_sources' => [
                'storefront' => 'parts.price PLN',
                'allegro' => 'parts.allegro_price (maintained by Part::saving from parts.price)',
                'ovoko' => 'parts.ovoko_price',
                'ebay_de' => 'parts.ebay_price PLN; EUR conversion remains in existing NBP listing/sync flow',
            ],
            'part_preview' => $part ? $this->previewPart($part) : null,
        ];
    }

    /** @param array<int, string> $changedPriceFields @return array<string, mixed> */
    private function planChannel(Part $part, string $channel, array $changedPriceFields): array
    {
        $listing = $this->listingFor($part, $channel);
        $targetPrice = $this->targetPrice($part, $channel);
        $marketplace = $channel === 'ebay_de' ? 'ebay' : $channel;

        MarketplaceSyncLog::query()->create([
            'marketplace' => $marketplace,
            'marketplace_listing_id' => $listing?->id,
            'part_id' => $part->id,
            'action' => 'part_save_price_sync_plan',
            'status' => 'skipped',
            'external_id' => $listing?->external_offer_id ?: $listing?->external_listing_id,
            'message' => 'Price sync stage 2 is diagnostic-only; no marketplace write was sent.',
            'payload' => [
                'channel' => $channel,
                'marketplace_write' => false,
                'changed_price_fields' => $changedPriceFields,
                'target_price' => $targetPrice,
                'currency' => $channel === 'ebay_de' ? 'PLN_BEFORE_EXISTING_NBP_EUR_CONVERSION' : 'PLN',
                'protected_fields_not_touched' => ['quantity', 'status', 'publication', 'description', 'photos', 'category'],
            ],
            'created_at' => now(),
        ]);

        return ['channel' => $channel, 'listing_id' => $listing?->id, 'target_price' => $targetPrice, 'marketplace_write' => false, 'status' => 'planned_skipped'];
    }

    private function listingFor(Part $part, string $channel): ?MarketplaceListing
    {
        $marketplace = $channel === 'ebay_de' ? 'ebay' : $channel;

        return $part->marketplaceListings()
            ->where('marketplace', $marketplace)
            ->when($channel === 'ebay_de', fn ($query) => $query->where(function ($q): void {
                $q->where('external_inventory_id', 'like', '%DE%')->orWhere('sku', 'like', '%DE%')->orWhere('marketplace', 'ebay');
            }))
            ->latest('id')
            ->first();
    }

    private function targetPrice(Part $part, string $channel): ?float
    {
        $value = match ($channel) {
            'allegro' => $part->allegro_price,
            'ovoko' => $part->ovoko_price,
            'ebay_de' => $part->ebay_price,
            default => null,
        };

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /** @return array<string, mixed> */
    private function previewPart(Part $part): array
    {
        return collect($this->enabledChannels())
            ->mapWithKeys(fn (string $channel): array => [$channel => ['target_price' => $this->targetPrice($part, $channel), 'listing_id' => $this->listingFor($part, $channel)?->id]])
            ->all();
    }
}

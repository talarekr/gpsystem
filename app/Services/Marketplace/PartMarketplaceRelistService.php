<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PartMarketplaceRelistService
{
    public function __construct(private readonly PartAvailabilityEventService $availabilityEvents) {}

    public function dryRun(Part|int $part): array
    {
        $part = $this->part($part);
        return $this->summary($part, false, null);
    }

    public function apply(Part|int $part): array
    {
        $part = $this->part($part);
        $before = MarketplaceListing::query()->where('part_id', $part->id)->count();

        DB::transaction(function () use ($part): void {
            $locked = Part::query()->whereKey($part->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'status' => 'ready',
                'quantity' => max(1, (int) ($locked->quantity ?? 0)),
                'is_visible_storefront' => true,
                'needs_listing' => false,
                'sale_source' => null,
                'sold_at' => null,
            ])->save();

            MarketplaceListing::query()->where('part_id', $locked->id)->get()->each(function (MarketplaceListing $listing): void {
                if ($this->externalId($listing) === null) return;
                $listing->forceFill([
                    'quantity' => 1,
                    'status' => 'reactivation_pending',
                    'sync_status' => 'pending_reactivation',
                    'last_error' => null,
                ])->save();
            });
        });

        $result = $this->availabilityEvents->restored([
            'source_channel' => 'manual_stock_change',
            'part_id' => $part->id,
            'reason' => 'admin_relist_part_apply',
        ]);

        $fresh = Part::query()->with('marketplaceListings')->findOrFail($part->id);
        $summary = $this->summary($fresh, true, $result);
        $summary['duplicate_guard'] = [
            'marketplace_listings_before' => $before,
            'marketplace_listings_after' => MarketplaceListing::query()->where('part_id', $part->id)->count(),
            'created_new_marketplace_listings' => MarketplaceListing::query()->where('part_id', $part->id)->count() - $before,
        ];

        return $summary;
    }

    public function diagnostic(Part|int $part): array
    {
        return $this->dryRun($part) + ['debug_only' => true];
    }

    private function summary(Part $part, bool $apply, ?array $applyResult): array
    {
        $part->loadMissing('marketplaceListings');
        return [
            'ok' => true,
            'dry_run' => ! $apply,
            'apply' => $apply,
            'part' => [
                'id' => $part->id,
                'name' => $part->name,
                'status' => $part->status,
                'quantity' => $part->quantity,
                'admin_local_availability' => $part->adminLocalAvailability(),
                'would_set_status' => $part->status === 'ready' ? null : 'ready',
                'would_set_quantity' => (int) $part->quantity > 0 ? null : 1,
            ],
            'marketplace_listings' => $part->marketplaceListings->map(fn (MarketplaceListing $listing): array => $this->listingSummary($listing))->values()->all(),
            'apply_result' => $applyResult,
            'notes' => [
                'No marketplace_listing rows are deleted or created by this action.',
                'Allegro uses existing offer id and attempts publication ACTIVE.',
                'eBay uses existing SKU/inventory mapping and sets inventory quantity to 1; no duplicate listing is created.',
                'Ovoko uses existing part id/mapping and changes CRM part status to in stock.',
            ],
        ];
    }

    private function listingSummary(MarketplaceListing $listing): array
    {
        $externalId = $this->externalId($listing);
        $eligible = $externalId !== null;
        return [
            'marketplace_listing_id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'external_offer_id' => $this->blankNull($listing->external_offer_id),
            'external_listing_id' => $this->blankNull($listing->external_listing_id),
            'external_inventory_id' => $this->blankNull($listing->external_inventory_id),
            'sku' => $this->blankNull($listing->sku),
            'url' => $this->blankNull($listing->url),
            'status' => $this->blankNull($listing->status),
            'sync_status' => $this->blankNull($listing->sync_status),
            'match_status' => $this->blankNull($listing->match_status),
            'last_api_status' => $this->blankNull($listing->last_api_status),
            'last_error' => $this->blankNull($listing->last_error),
            'qualifies_for_relist' => $eligible,
            'relist_action' => $eligible ? $this->actionFor($listing) : null,
            'not_relisted_reason' => $eligible ? null : 'missing_external_offer_id_external_listing_id_or_sku',
            'will_create_duplicate_listing' => false,
        ];
    }

    private function actionFor(MarketplaceListing $listing): string
    {
        return match (true) {
            in_array($listing->marketplace, ['allegro', 'allegro_main'], true) => 'activate_existing_allegro_offer',
            $listing->marketplace === 'ovoko' => 'restore_existing_ovoko_part_status',
            str_starts_with((string) $listing->marketplace, 'ebay') => 'set_existing_ebay_inventory_quantity_to_1',
            default => 'unsupported_channel_manual_relist_required',
        };
    }

    private function externalId(MarketplaceListing $listing): ?string
    {
        return match (true) {
            in_array($listing->marketplace, ['allegro', 'allegro_main'], true) => $this->blankNull($listing->external_offer_id) ?: $this->blankNull($listing->external_listing_id),
            $listing->marketplace === 'ovoko' => $this->blankNull($listing->external_listing_id) ?: $this->blankNull($listing->external_offer_id) ?: $this->blankNull(Arr::get($listing->raw_payload ?: [], 'metadata.ovoko_part_id')),
            str_starts_with((string) $listing->marketplace, 'ebay') => $this->blankNull($listing->sku) ?: $this->blankNull($listing->external_offer_id) ?: $this->blankNull($listing->external_listing_id),
            default => $this->blankNull($listing->external_offer_id) ?: $this->blankNull($listing->external_listing_id),
        };
    }

    private function part(Part|int $part): Part
    { return $part instanceof Part ? $part->fresh(['marketplaceListings']) : Part::query()->with('marketplaceListings')->findOrFail($part); }

    private function blankNull(mixed $value): ?string
    { $value = trim((string) $value); return $value === '' ? null : $value; }
}

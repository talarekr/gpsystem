<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Collection;

class SoldPartMarketplaceEndPlanService
{
    /**
     * @return array{dry_run: bool, part_id: int|null, status: string|null, listings_count: int, listings: array<int, array{marketplace: string, external_offer_id: string|null, external_listing_id: string|null, status: string|null, sync_status: string|null, action: string}>}
     */
    public function planForPart(Part $part): array
    {
        $listings = $part->relationLoaded('marketplaceListings')
            ? $part->marketplaceListings
            : $part->marketplaceListings()->get();

        $planned = $listings
            ->filter(fn (MarketplaceListing $listing): bool => $this->shouldEnd($listing))
            ->map(fn (MarketplaceListing $listing): array => [
                'marketplace' => (string) $listing->marketplace,
                'external_offer_id' => $this->blankNull($listing->external_offer_id),
                'external_listing_id' => $this->blankNull($listing->external_listing_id),
                'status' => $this->blankNull($listing->status),
                'sync_status' => $this->blankNull($listing->sync_status),
                'action' => 'would_end_listing_no_api_write',
            ])
            ->values();

        return [
            'dry_run' => true,
            'part_id' => $part->id,
            'status' => $part->status,
            'listings_count' => $planned->count(),
            'listings' => $planned->all(),
        ];
    }

    private function shouldEnd(MarketplaceListing $listing): bool
    {
        if ($this->blankNull($listing->external_offer_id) === null && $this->blankNull($listing->external_listing_id) === null) {
            return false;
        }

        $status = strtolower((string) ($listing->last_api_status ?: $listing->status ?: $listing->sync_status));

        return ! in_array($status, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'ignored'], true);
    }

    private function blankNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

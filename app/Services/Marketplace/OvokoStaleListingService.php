<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\Part;

class OvokoStaleListingService
{
    public const UNLINK_FLAG = 'metadata.ovoko_unlinked_for_republish';

    public function ignoredForPublish(MarketplaceListing $listing): bool
    {
        return $listing->marketplace === 'ovoko' && (bool) data_get($listing->raw_payload, self::UNLINK_FLAG, false);
    }

    public function qualifies(Part $part, MarketplaceListing $listing, bool $activeByResolver): array
    {
        $reasons = [];
        $blockers = [];
        if ($listing->marketplace !== 'ovoko') $blockers[] = 'not_ovoko';
        if (! filled($listing->external_offer_id) && ! filled($listing->external_listing_id) && ! filled($listing->url)) $blockers[] = 'missing_external_id_or_url';

        $status = strtolower((string) $listing->status);
        $sync = strtolower((string) $listing->sync_status);
        $match = strtolower((string) $listing->match_status);
        if (in_array($status, ['imported', 'mapped', 'draft', 'incomplete', 'stale', 'historical', 'unlinked'], true) || $sync === 'mapped') $reasons[] = 'imported_mapped_draft_incomplete_or_stale'; else $blockers[] = 'status_not_stale_imported_or_mapped';
        if (in_array($match, ['confirmed', 'matched'], true)) $reasons[] = 'match_confirmed_or_matched'; else $blockers[] = 'match_not_confirmed_or_matched';
        if (! $activeByResolver) $reasons[] = 'not_active_by_local_resolver'; else $blockers[] = 'active_by_local_resolver';
        if (in_array($status, ['active', 'published', 'in_stock', 'in-stock', 'for_sale', 'for-sale'], true) || in_array($sync, ['active', 'published', 'in_stock', 'in-stock', 'for_sale', 'for-sale'], true)) $blockers[] = 'active_status_or_sync_status';

        $partDraftNeedsListing = (bool) $part->needs_listing || in_array((string) $part->status, ['draft', 'new', 'pending', 'to_list'], true) || (string) $part->status !== 'ready';
        if ($partDraftNeedsListing || ! $activeByResolver) $reasons[] = 'part_needs_listing_or_not_active_on_ovoko'; else $blockers[] = 'part_ready_active_ovoko_listing';
        if ($this->missingSentPriceOrHistorical($listing)) $reasons[] = 'missing_sent_price_or_historical_imported'; else $blockers[] = 'sent_price_present_and_not_historical';
        if ((string) $part->status === 'sold') $blockers[] = 'part_sold';

        return ['qualifies' => $blockers === [], 'reasons' => array_values(array_unique($reasons)), 'safety_blockers' => array_values(array_unique($blockers))];
    }

    private function missingSentPriceOrHistorical(MarketplaceListing $listing): bool
    {
        return ! is_numeric($listing->price) || (float) $listing->price <= 0 || in_array(strtolower((string) $listing->status), ['imported', 'mapped', 'draft', 'incomplete', 'stale', 'historical', 'unlinked'], true);
    }
}

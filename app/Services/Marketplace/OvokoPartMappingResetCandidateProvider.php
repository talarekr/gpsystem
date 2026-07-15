<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OvokoPartMappingResetCandidateProvider
{
    /** @return array<int, int> */
    public function ids(int $limit = 1000, array $excludePartIds = []): array
    {
        return $this->query($excludePartIds)
            ->limit(max(1, min(1000, $limit)))
            ->pluck('parts.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(int $limit = 100, array $excludePartIds = []): array
    {
        return $this->query($excludePartIds)
            ->limit(max(1, min(1000, $limit)))
            ->get()
            ->map(fn ($row): array => [
                'part_id' => (int) $row->part_id,
                'marketplace_listing_id' => (int) $row->marketplace_listing_id,
                'sku' => $row->part_sku,
                'part_code' => $row->part_code,
                'listing_sku' => $row->listing_sku,
                'status' => $row->listing_status,
                'price' => $row->price,
                'ovoko_price' => $row->ovoko_price,
                'needs_listing' => (bool) $row->needs_listing,
                'is_visible_storefront' => (bool) $row->is_visible_storefront,
                'external_offer_id' => $row->external_offer_id,
                'external_listing_id' => $row->external_listing_id,
                'external_inventory_id' => $row->external_inventory_id,
                'url' => $row->url,
                'reset_recommended_now_strict' => true,
                'missing_price_for_strict_reset' => true,
                'identity_looks_like_gps_gmail' => true,
                'is_to_publish' => true,
                'is_in_parts_menu' => false,
            ])
            ->values()
            ->all();
    }

    public function strictCheck(Part $part, ?MarketplaceListing $listing): array
    {
        if (! $listing || $listing->marketplace !== 'ovoko') return ['ok' => false, 'reason' => 'marketplace_listing_is_not_ovoko'];
        if (! $this->hasActiveMapping($listing)) return ['ok' => false, 'reason' => 'missing_active_ovoko_mapping_or_link'];
        if (! $this->identityLooksLikeGpsGmail($part, $listing)) return ['ok' => false, 'reason' => 'not_gps_gmail_identity'];
        if ($this->positivePrice($part->price) || $this->positivePrice($part->ovoko_price) || $this->positivePrice($listing->price)) return ['ok' => false, 'reason' => 'has_price_or_ovoko_price'];
        if (! (bool) $part->needs_listing) return ['ok' => false, 'reason' => 'not_to_publish_queue'];
        if ((bool) $part->is_visible_storefront) return ['ok' => false, 'reason' => 'is_in_parts_menu'];
        if (strcasecmp((string) $listing->status, 'imported') !== 0) return ['ok' => false, 'reason' => 'status_not_imported'];
        if (strcasecmp((string) $part->status, 'published') === 0) return ['ok' => false, 'reason' => 'published'];
        if ($this->hasActiveLiveSignal($part, $listing)) return ['ok' => false, 'reason' => 'reset_risk_level_not_low'];

        return ['ok' => true, 'reason' => null];
    }

    public function query(array $excludePartIds = []): Builder
    {
        $this->assertSchema();

        return DB::table('marketplace_listings')
            ->join('parts', 'parts.id', '=', 'marketplace_listings.part_id')
            ->select([
                'parts.id as part_id', 'parts.sku as part_sku', 'parts.part_number as part_code', 'parts.price', 'parts.ovoko_price', 'parts.needs_listing', 'parts.is_visible_storefront',
                'marketplace_listings.id as marketplace_listing_id', 'marketplace_listings.sku as listing_sku', 'marketplace_listings.status as listing_status',
                'marketplace_listings.external_offer_id', 'marketplace_listings.external_listing_id', 'marketplace_listings.external_inventory_id', 'marketplace_listings.url',
            ])
            ->where('marketplace_listings.marketplace', '=', 'ovoko')
            ->where('marketplace_listings.status', '=', 'imported')
            ->where(function ($q): void {
                $q->whereNotNull('marketplace_listings.external_offer_id')
                    ->orWhereNotNull('marketplace_listings.external_listing_id')
                    ->orWhereNotNull('marketplace_listings.url');
            })
            ->where(function ($q): void {
                $q->where('marketplace_listings.sku', 'like', 'GPS-GMAIL-%')
                    ->orWhere('parts.sku', 'like', 'GPS-GMAIL-%')
                    ->orWhere('parts.part_number', 'like', 'GPS-GMAIL-%');
            })
            ->where(function ($q): void { $q->whereNull('parts.price')->orWhere('parts.price', '<=', 0); })
            ->where(function ($q): void { $q->whereNull('parts.ovoko_price')->orWhere('parts.ovoko_price', '<=', 0); })
            ->where('parts.needs_listing', '=', true)
            ->where('parts.is_visible_storefront', '=', false)
            ->whereNotIn('parts.status', ['published'])
            ->when($excludePartIds !== [], fn ($q) => $q->whereNotIn('parts.id', array_values(array_unique(array_map('intval', $excludePartIds)))))
            ->orderBy('parts.id');
    }

    private function assertSchema(): void
    {
        foreach (['parts' => ['id', 'sku', 'part_number', 'price', 'ovoko_price', 'needs_listing', 'is_visible_storefront', 'status'], 'marketplace_listings' => ['id', 'part_id', 'marketplace', 'status', 'sync_status', 'match_status', 'external_offer_id', 'external_listing_id', 'external_inventory_id', 'url', 'sku', 'price']] as $table => $columns) {
            if (! Schema::hasTable($table)) throw new \RuntimeException("Missing required table: {$table}");
            $missing = array_values(array_filter($columns, fn (string $column): bool => ! Schema::hasColumn($table, $column)));
            if ($missing !== []) throw new \RuntimeException('Missing required columns on '.$table.': '.implode(', ', $missing));
        }
    }

    private function hasActiveMapping(MarketplaceListing $l): bool { return (filled($l->external_offer_id) || filled($l->external_listing_id) || filled($l->url)); }
    private function identityLooksLikeGpsGmail(Part $p, ?MarketplaceListing $l): bool { foreach ([$p->sku, $p->part_number, $l?->sku] as $v) if (preg_match('/^GPS-GMAIL-/i', (string) $v) === 1) return true; return false; }
    private function positivePrice(mixed $price): bool { return is_numeric($price) && (float) $price > 0; }
    private function hasActiveLiveSignal(Part $p, MarketplaceListing $l): bool { return in_array((string) $p->status, ['ready', 'published'], true) || in_array((string) $l->status, ['published', 'active', 'live', 'publication_pending', 'PUBLISHED', 'ACTIVE'], true) || in_array((string) $l->sync_status, ['published', 'active', 'live', 'PUBLISHED', 'ACTIVE'], true) || in_array((string) $l->last_api_status, ['published', 'active', 'live', 'PUBLISHED', 'ACTIVE'], true); }
}

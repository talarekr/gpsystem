<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use Illuminate\Support\Facades\Schema;

class MarketplaceListingUrlBackfillService
{
    /** @return array<string,mixed> */
    public function run(string $marketplace, ?string $channel = null, bool $apply = false, int $limit = 100, int $offset = 0, bool $onlyMissing = false, bool $includeExistingInvalid = false, ?int $listingId = null, ?int $partId = null): array
    {
        if (! Schema::hasTable('marketplace_listings')) {
            throw new \RuntimeException('Required table marketplace_listings does not exist.');
        }

        $marketplace = strtolower(trim($marketplace));
        abort_unless(in_array($marketplace, ['allegro', 'ebay'], true), 422, 'Supported marketplace values: allegro, ebay.');

        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $marketplaces = $this->marketplaceCodes($marketplace, $channel);

        $baseQuery = MarketplaceListing::query()->with('account:id,code,marketplace')->whereIn('marketplace', $marketplaces);
        if ($listingId !== null) $baseQuery->whereKey($listingId);
        if ($partId !== null) $baseQuery->where('part_id', $partId);

        $totalListingsCount = (clone $baseQuery)->count();
        $totalMissingUrlCount = (clone $baseQuery)->where(fn ($q) => $q->whereNull('url')->orWhere('url', ''))->count();
        $totalWithExternalIdCount = (clone $baseQuery)->where(function ($query): void {
            $query->whereNotNull('external_offer_id')->orWhereNotNull('external_listing_id');
            if (Schema::hasColumn('marketplace_listings', 'external_id')) {
                $query->orWhereNotNull('external_id');
            }
        })->count();

        $summary = [
            'marketplace' => $marketplace, 'channel' => $channel, 'limit_requested' => $limit, 'limit_applied' => $limit,
            'offset_requested' => $offset, 'offset_applied' => $offset, 'first_inspected_listing_id' => null,
            'last_inspected_listing_id' => null, 'inspected_listing_ids_sample' => [], 'total_listings_count' => $totalListingsCount,
            'total_missing_url_count' => $totalMissingUrlCount, 'total_with_external_marketplace_id_count' => $totalWithExternalIdCount, 'inspected' => 0, 'inspected_with_external_marketplace_id' => 0, 'already_has_url' => 0,
            'missing_url_with_valid_external_id' => 0, 'would_generate' => 0, 'would_update' => 0, 'updated' => 0,
            'invalid_external_id' => 0, 'missing_external_id' => 0, 'suspicious_existing_url' => 0, 'image_url_rejected' => 0, 'gpsw_external_id' => 0, 'suspected_stale_existing_ebay_url' => 0, 'pre_june_listing' => 0, 'skipped' => 0,
        ];
        $results = [];
        $ids = [];

        $rows = (clone $baseQuery)->orderBy('marketplace_listings.id', 'asc')->offset($offset)->limit($limit)->get();
        foreach ($rows as $listing) {
            $ids[] = (int) $listing->id;
            $summary['inspected']++;
            $existingUrl = $this->blankNull($listing->url);
            $validExisting = $this->validMarketplaceUrl($marketplace, $existingUrl);
            $suspicious = $existingUrl !== null && ! $validExisting;
            $imageRejected = $suspicious && $this->isImageOrStorageUrl($existingUrl);
            $resolvedId = $this->resolveExternalId($listing);
            if ($resolvedId !== null) $summary['inspected_with_external_marketplace_id']++;
            $isGpswExternalId = $this->isGpswExternalId($listing->external_offer_id) || $this->isGpswExternalId($listing->external_listing_id) || $this->isGpswExternalId($this->columnValue($listing, 'external_id'));
            $isPreJuneListing = $marketplace === 'ebay' && optional($listing->created_at)->lt(\Carbon\Carbon::parse('2026-06-01 00:00:00'));
            if ($isGpswExternalId) $summary['gpsw_external_id']++;
            if ($isPreJuneListing) $summary['pre_june_listing']++;
            if ($marketplace === 'ebay' && $existingUrl !== null && $validExisting && ($isPreJuneListing || $this->hasEndedMarker($listing))) $summary['suspected_stale_existing_ebay_url']++;
            $generatedUrl = $resolvedId !== null && ! $isGpswExternalId && preg_match('/^\d+$/', $resolvedId) === 1 ? $this->generateUrl($marketplace, $listing, $resolvedId) : null;
            $action = 'skipped_has_url';
            $reason = 'Listing already has a valid marketplace URL.';

            if ($existingUrl !== null && $validExisting) {
                $summary['already_has_url']++; $summary['skipped']++;
                if ($marketplace === 'ebay' && ($isPreJuneListing || $this->hasEndedMarker($listing))) { $action = 'needs_audit_existing_url'; $reason = 'Existing eBay URL may point to a pre-2026-06-01 or ended listing; use ebay-listing-audit before treating it as active.'; }
                if ($onlyMissing) continue;
            } else {
                if ($suspicious) { $summary['suspicious_existing_url']++; if ($imageRejected) $summary['image_url_rejected']++; }
                if ($resolvedId === null) {
                    $summary['missing_external_id']++; $summary['skipped']++; $action = 'missing_external_id'; $reason = 'No external marketplace item/offer ID found; local part/listing IDs are not used.';
                } elseif ($isGpswExternalId || preg_match('/^\d+$/', $resolvedId) !== 1 || $generatedUrl === null) {
                    $summary['invalid_external_id']++; $summary['skipped']++; $action = 'invalid_external_id'; $reason = 'External marketplace ID is not numeric or channel/domain could not be resolved.';
                } elseif ($suspicious && ! $includeExistingInvalid) {
                    $summary['skipped']++; $action = 'rejected_existing_url'; $reason = $imageRejected ? 'Existing URL looks like a GPSwiss storage/image URL; pass include_existing_invalid=1 to replace locally.' : 'Existing URL is suspicious; pass include_existing_invalid=1 to replace locally.';
                } else {
                    $summary['missing_url_with_valid_external_id']++; $summary['would_generate']++; $summary['would_update']++;
                    $action = $apply ? 'updated' : 'would_update'; $reason = $existingUrl === null ? 'Missing URL and valid external marketplace ID is available.' : 'Suspicious existing URL can be replaced from valid external marketplace ID.';
                    if ($apply) { $listing->url = $generatedUrl; $listing->save(); $summary['updated']++; }
                }
            }

            $results[] = [
                'marketplace_listing_id' => $listing->id, 'local_part_id' => $listing->part_id, 'marketplace' => $listing->marketplace,
                'channel' => $this->listingChannel($listing), 'account_code' => $listing->account?->code, 'marketplace_id' => strtoupper($this->listingChannel($listing) ?? $listing->marketplace),
                'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id, 'external_id' => $this->columnValue($listing, 'external_id'),
                'resolved_marketplace_item_id' => $resolvedId, 'existing_url' => $existingUrl, 'generated_url' => $generatedUrl, 'gpsw_external_id' => $isGpswExternalId, 'pre_june_listing' => $isPreJuneListing, 'needs_ebay_audit' => $marketplace === 'ebay' && ($isGpswExternalId || $isPreJuneListing || $this->hasEndedMarker($listing)), 'action' => $action, 'reason' => $reason,
            ];
        }
        $summary['first_inspected_listing_id'] = $ids[0] ?? null;
        $summary['last_inspected_listing_id'] = $ids === [] ? null : $ids[array_key_last($ids)];
        $summary['inspected_listing_ids_sample'] = array_slice($ids, 0, 10);

        return ['mode' => $apply ? 'apply' : 'dry_run', 'summary' => $summary, 'results' => array_slice($results, 0, 20), 'warnings' => ['For eBay, GPSW-* values are SKU/inventory IDs, not public item IDs; URL generation is blocked for them. Existing eBay URLs on pre-2026-06-01 or locally ended records are not proof of an active listing; use /admin/tools/marketplace/ebay-listing-audit.']];
    }

    private function marketplaceCodes(string $marketplace, ?string $channel): array { if ($marketplace === 'allegro') return ['allegro']; if ($channel) return [$channel]; return ['ebay', 'ebay_de', 'ebay_fr']; }
    private function blankNull(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function listingChannel(MarketplaceListing $listing): ?string { return $this->blankNull($listing->raw_payload['channel'] ?? null) ?? $this->blankNull($listing->account?->code) ?? $listing->marketplace; }
    private function columnValue(MarketplaceListing $listing, string $column): ?string { return Schema::hasColumn('marketplace_listings', $column) ? $this->blankNull($listing->{$column}) : null; }
    private function resolveExternalId(MarketplaceListing $listing): ?string { foreach ([$listing->external_offer_id, $listing->external_listing_id, $this->columnValue($listing, 'external_id'), $listing->raw_payload['allegro_offer_id'] ?? null, $listing->raw_payload['external_offer_id'] ?? null, $listing->raw_payload['ebay']['item_id'] ?? null, $listing->raw_payload['ebay']['listing_id'] ?? null] as $id) { $id = $this->blankNull($id); if ($id !== null) return $id; } return null; }
    private function generateUrl(string $marketplace, MarketplaceListing $listing, string $id): ?string { if ($marketplace === 'allegro') return 'https://allegro.pl/oferta/'.$id; $channel = strtolower((string) $this->listingChannel($listing)); return match ($channel) { 'ebay_de', 'ebay-de', 'ebay_de_account', 'ebay_de_main' => 'https://www.ebay.de/itm/'.$id, 'ebay_fr', 'ebay-fr', 'ebay_fr_account', 'ebay_fr_main' => 'https://www.ebay.fr/itm/'.$id, default => $listing->marketplace === 'ebay_de' ? 'https://www.ebay.de/itm/'.$id : ($listing->marketplace === 'ebay_fr' ? 'https://www.ebay.fr/itm/'.$id : null), }; }
    private function validMarketplaceUrl(string $marketplace, ?string $url): bool { if ($url === null) return false; return $marketplace === 'allegro' ? preg_match('#^https?://([^/]+\.)?allegro\.pl/oferta/[^/]+#i', $url) === 1 : preg_match('#^https?://www\.ebay\.(de|fr)/itm/\d+#i', $url) === 1; }
    private function isImageOrStorageUrl(string $url): bool { return preg_match('#gpswiss\.pl/storage/|\.(jpg|jpeg|png|webp)(\?|$)#i', $url) === 1; }
    private function isGpswExternalId(mixed $value): bool { return preg_match('/^GPSW-\d+$/i', trim((string) $value)) === 1; }
    private function hasEndedMarker(MarketplaceListing $listing): bool { $raw = $listing->raw_payload ?: []; $status = strtolower((string) ($listing->last_api_status ?? $listing->status ?? ($raw['status'] ?? ($raw['ebay']['status'] ?? '')))); return str_contains($status, 'end') || in_array($status, ['inactive','completed','deleted','archived'], true) || filled($raw['ended_at'] ?? ($raw['ebay']['ended_at'] ?? null)); }
}


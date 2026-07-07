<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EbayMarketplaceDiagnoseController extends Controller
{
    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver): JsonResponse
    {
        $checkApi = $request->boolean('check_api', true);
        $applyInactive = $request->boolean('apply_inactive', false);
        $limit = max(1, min(500, (int) $request->integer('limit', 100)));
        $partIds = $this->partIds($request);

        $query = Part::query()->with(['marketplaceListings.account'])->orderBy('id');
        if ($partIds !== []) {
            $query->whereIn('id', $partIds);
        } else {
            $query->where('status', 'ready')->where('quantity', '>', 0)->whereHas('marketplaceListings', function ($q): void {
                $q->whereIn('marketplace', ['ebay_de', 'ebay_fr', 'ebay'])
                    ->where(fn ($qq) => $qq->whereNotNull('external_offer_id')->orWhereNotNull('external_listing_id')->orWhereNotNull('url'));
            })->limit($limit);
        }

        $parts = $query->get();
        $rows = $parts->map(fn (Part $part): array => $this->diagnosePart($part, $resolver, $checkApi, $applyInactive))->values()->all();

        return response()->json([
            'ok' => true,
            'read_only_ebay' => true,
            'local_mutation' => $applyInactive ? 'marked_api_ended_or_not_found_as_historical' : false,
            'input' => ['part_ids' => $partIds, 'bulk_mode' => $partIds === [], 'limit' => $limit, 'check_api' => $checkApi],
            'summary' => $this->summary($rows),
            'rows' => $rows,
            'usage' => [
                'one_or_many_parts' => '/admin/tools/ebay/marketplace-diagnose?part_ids=886,887&check_api=1',
                'bulk_audit' => '/admin/tools/ebay/marketplace-diagnose?check_api=1&limit=250',
                'apply_local_historical_mark' => '/admin/tools/ebay/marketplace-diagnose?check_api=1&apply_inactive=1&limit=250',
            ],
        ]);
    }

    private function diagnosePart(Part $part, PartMarketplaceStatusResolver $resolver, bool $checkApi, bool $applyInactive): array
    {
        $listings = $part->marketplaceListings->whereIn('marketplace', ['ebay_de', 'ebay_fr', 'ebay'])->values();
        $listingRows = $listings->map(function (MarketplaceListing $listing) use ($checkApi, $applyInactive): array {
            $api = $checkApi ? $this->apiStatus($listing) : ['api_listing_status' => 'not_checked'];
            if ($applyInactive && in_array((string) ($api['api_listing_status'] ?? ''), ['ended', 'inactive', 'not_found', 'unavailable'], true)) {
                $listing->forceFill(['status' => 'ended', 'sync_status' => 'historical', 'last_api_status' => $api['api_listing_status'], 'not_seen_in_active_api_at' => now()])->save();
            }
            return [
                'id' => $listing->id,
                'marketplace' => $listing->marketplace,
                'status' => $listing->status,
                'sync_status' => $listing->sync_status,
                'match_status' => $listing->match_status,
                'last_api_status' => $listing->last_api_status,
                'last_error' => $listing->last_error,
                'external_offer_id' => $listing->external_offer_id,
                'external_listing_id' => $listing->external_listing_id,
                'url' => $listing->url,
                'resolved_listingUrl' => $listing->url,
                'resolved_externalOfferId' => $listing->external_offer_id ?: $listing->external_listing_id,
                'api' => $api,
                'duplicate_guard_would_block' => $this->isBlockingDuplicate($listing),
            ];
        })->all();

        $resolverRow = collect($resolver->rowsForPart($part))->firstWhere('key', 'ebay') ?: [];
        $apiStatuses = collect($listingRows)->pluck('api.api_listing_status')->filter()->values()->all();
        $classification = in_array('active', $apiStatuses, true) ? 'active OK' : (in_array('not_found', $apiStatuses, true) ? 'not_found should_show_x_and_allow_new_publish' : (array_intersect($apiStatuses, ['ended', 'inactive', 'unavailable']) ? 'ended should_show_x_and_allow_new_publish' : (in_array('error', $apiStatuses, true) ? 'api_error needs_review' : 'local_only needs_review')));

        return [
            'part' => ['id' => $part->id, 'sku' => $part->sku, 'part_number' => $part->part_number, 'status' => $part->status, 'quantity' => $part->quantity, 'adminLocalAvailability' => $part->adminLocalAvailability()],
            'marketplace_listings' => $listingRows,
            'resolver_ebay' => [
                'has_link' => $resolverRow['has_link'] ?? false,
                'url' => $resolverRow['url'] ?? null,
                'is_active' => $resolverRow['is_active'] ?? false,
                'icon' => $resolverRow['icon'] ?? null,
                'display_icon' => $resolverRow['display_icon'] ?? null,
                'reason' => $resolverRow['reason'] ?? null,
                'title' => $resolverRow['title'] ?? null,
            ],
            'duplicate_guard_would_block' => collect($listingRows)->contains('duplicate_guard_would_block', true),
            'audit_classification' => $classification,
        ];
    }

    private function apiStatus(MarketplaceListing $listing): array
    {
        $itemId = $this->itemId($listing);
        if (! $itemId || ! ctype_digit($itemId)) return ['api_listing_status' => 'not_checked', 'error' => 'missing_numeric_item_id'];
        $account = $listing->account ?: MarketplaceAccount::query()->where('code', $listing->marketplace)->orWhere('marketplace', $listing->marketplace)->first();
        if (! $account) return ['api_listing_status' => 'error', 'error' => 'missing_marketplace_account', 'item_id' => $itemId];
        $marketplaceId = $listing->marketplace === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE';
        try { return (new EbayApiClient($listing->marketplace, $account))->getListingStatusByItemId($itemId, $marketplaceId); }
        catch (\Throwable $e) { return ['api_listing_status' => 'error', 'item_id' => $itemId, 'error' => $e->getMessage(), 'exception' => $e::class]; }
    }

    private function isBlockingDuplicate(MarketplaceListing $listing): bool
    {
        $status = strtolower((string) $listing->status);
        $api = strtolower((string) $listing->last_api_status);
        return in_array($status, ['active', 'published', 'live'], true)
            && ! in_array($api, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'unavailable', 'failed', 'error'], true)
            && $listing->not_seen_in_active_api_at === null
            && (filled($listing->external_listing_id) || filled($listing->external_offer_id));
    }

    private function itemId(MarketplaceListing $listing): ?string
    {
        if (preg_match('#/itm/(\d+)#', (string) $listing->url, $m)) return $m[1];
        foreach ([$listing->external_listing_id, $listing->external_offer_id, data_get($listing->raw_payload, 'ebay.item_id'), data_get($listing->raw_payload, 'item_id')] as $id) if (filled($id)) return (string) $id;
        return null;
    }

    private function partIds(Request $request): array
    {
        $raw = $request->input('part_ids', $request->input('part_id', ''));
        return collect(is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw))->filter()->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
    }

    private function summary(array $rows): array
    {
        return collect($rows)->countBy('audit_classification')->all() + ['total_parts' => count($rows), 'duplicate_guard_would_block' => collect($rows)->where('duplicate_guard_would_block', true)->count()];
    }
}

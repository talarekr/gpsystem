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
use Illuminate\View\View;

class EbayMarketplaceDiagnoseController extends Controller
{
    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver): JsonResponse|View
    {
        $action = (string) $request->input('action', '');
        $wantsJson = $request->expectsJson() || $request->input('format') === 'json' || $request->ajax();

        if (! in_array($action, ['part', 'bulk', 'apply_inactive'], true)) {
            $payload = $this->emptyPayload();
            return $wantsJson ? response()->json($payload) : view('admin.tools.ebay.marketplace-diagnose', $payload);
        }

        if ($action === 'apply_inactive') {
            abort_unless($request->isMethod('post'), 405, 'apply_inactive requires POST.');
            abort_unless($request->boolean('confirm_apply_inactive'), 422, 'Confirmation is required.');
        }

        $checkApi = $request->boolean('check_api', false);
        $applyInactive = $action === 'apply_inactive';
        $limit = max(1, min(500, (int) $request->integer('limit', $action === 'bulk' ? 20 : 100)));
        $offset = max(0, (int) $request->integer('offset', 0));
        $partIds = $this->partIds($request);

        if ($action === 'part') {
            $query = Part::query()->with(['marketplaceListings.account'])->orderBy('id');
            $query->whereIn('id', $partIds ?: [-1]);
        } else {
            $query = $this->basePartQuery();
            if ($partIds !== []) {
                $query->whereIn('id', $partIds);
            }
            $query->limit($limit)->offset($offset);
        }

        $parts = $query->get();
        $rows = $parts->map(fn (Part $part): array => $this->diagnosePart($part, $resolver, $checkApi, $applyInactive))->values()->all();
        $total = $action === 'bulk' ? $this->basePartQuery()->count() : count($rows);

        $payload = [
            'ok' => true,
            'read_only_ebay' => ! $applyInactive,
            'local_mutation' => $applyInactive ? 'marked_api_ended_or_not_found_as_historical' : false,
            'input' => ['action' => $action, 'part_ids' => $partIds, 'bulk_mode' => $action === 'bulk', 'limit' => $limit, 'offset' => $offset, 'check_api' => $checkApi],
            'progress' => ['processed' => min($offset + count($rows), $total), 'batch_count' => count($rows), 'total' => $total, 'completed' => $offset + count($rows) >= $total],
            'summary' => $this->summary($rows),
            'rows' => $rows,
        ];

        return $wantsJson ? response()->json($payload) : view('admin.tools.ebay.marketplace-diagnose', $payload + ['ran' => true]);
    }

    private function emptyPayload(): array
    {
        return ['ok' => true, 'read_only_ebay' => true, 'local_mutation' => false, 'input' => ['action' => null, 'part_ids' => [], 'bulk_mode' => false, 'limit' => null, 'check_api' => false], 'progress' => ['processed' => 0, 'batch_count' => 0, 'total' => 0, 'completed' => false], 'summary' => $this->summary([]), 'rows' => [], 'ran' => false];
    }

    private function basePartQuery()
    {
        return Part::query()->with(['marketplaceListings.account'])->orderBy('id')
            ->where('status', 'ready')->where('quantity', '>', 0)->whereHas('marketplaceListings', function ($q): void {
                $q->whereIn('marketplace', ['ebay_de', 'ebay_fr', 'ebay'])
                    ->where(fn ($qq) => $qq->whereNotNull('external_offer_id')->orWhereNotNull('external_listing_id')->orWhereNotNull('url'));
            });
    }

    private function diagnosePart(Part $part, PartMarketplaceStatusResolver $resolver, bool $checkApi, bool $applyInactive): array
    {
        $listings = $part->marketplaceListings->whereIn('marketplace', ['ebay_de', 'ebay_fr', 'ebay'])->values();
        $listingRows = $listings->map(function (MarketplaceListing $listing) use ($checkApi, $applyInactive): array {
            $api = $checkApi ? $this->apiStatus($listing) : ['api_listing_status' => 'not_checked'];
            $publicItemId = $this->publicItemId($listing);
            $api['public_item_id'] = $publicItemId;
            $api = $this->withLocalEndDateFallback($listing, $api);
            if ($checkApi && $this->apiNeedsSellerSideVerification($api)) {
                $api['seller_side'] = $this->sellerSideApiStatus($listing);
                if ($this->realSellerListingId($api['seller_side']) === null && blank($api['seller_side']['offer_status'] ?? null) && blank($api['seller_side']['listing_status'] ?? null)) {
                    $api['seller_side']['verification_reason'] = $api['seller_side']['blocker'] ?? 'seller_verification_unavailable';
                }
                $api['seller_listing_id_matches_public_item_id'] = $this->sellerListingMatchesPublicItem($api['seller_side'], $publicItemId);
                if ($this->sellerSideConfirmsActive($api['seller_side'], $publicItemId)) {
                    $api['api_listing_status'] = 'active_seller_verified';
                    $api['seller_side_verified_active'] = true;
                }
            }
            if (($api['end_date_is_past'] ?? false) === true && ($api['api_listing_status'] ?? null) === 'active') $api['api_listing_status'] = 'ended';
            if ($applyInactive && in_array($listing->marketplace, ['ebay_de', 'ebay'], true) && $this->apiIsCertainEndedForHistory($api)) {
                $listing->forceFill(['status' => 'ended', 'sync_status' => 'historical', 'last_api_status' => $api['api_listing_status'], 'not_seen_in_active_api_at' => now()])->save();
            }
            return [
                'id' => $listing->id, 'marketplace' => $listing->marketplace, 'status' => $listing->status, 'sync_status' => $listing->sync_status, 'match_status' => $listing->match_status, 'last_api_status' => $listing->last_api_status, 'last_error' => $listing->last_error, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id, 'external_inventory_id' => $listing->external_inventory_id, 'sku' => $listing->sku, 'url' => $listing->url, 'resolved_listingUrl' => $listing->url, 'resolved_externalOfferId' => $listing->external_offer_id ?: $listing->external_listing_id, 'public_item_id' => $publicItemId, 'seller_offer_id' => data_get($api, 'seller_side.offer_id'), 'seller_listing_id' => $this->realSellerListingId(data_get($api, 'seller_side', [])), 'requested_listing_id' => data_get($api, 'seller_side.requested_listing_id'), 'offer_listing_id' => data_get($api, 'seller_side.offer_listing_id'), 'seller_listing_id_matches_public_item_id' => $this->sellerListingMatchesPublicItem(data_get($api, 'seller_side', []), $publicItemId), 'public_item_end_date' => $api['end_date'] ?? null, 'public_item_end_date_source' => $api['end_date_source'] ?? 'unavailable', 'public_item_end_past' => (bool) ($api['end_date_is_past'] ?? false), 'raw_payload_diagnostics' => $this->rawPayloadDiagnostics($listing, $api), 'seller_listing_status' => data_get($api, 'seller_side.listing_status'), 'seller_offer_status' => data_get($api, 'seller_side.offer_status'), 'seller_verification_reason' => data_get($api, 'seller_side.verification_reason'), 'listing_exists' => filled($listing->external_listing_id) || filled($listing->external_offer_id) || filled($listing->url), 'api' => $api, 'duplicate_guard_would_block' => $this->isBlockingDuplicate($listing, $api),
            ];
        })->all();

        $resolverRow = collect($resolver->rowsForPart($part))->firstWhere('key', 'ebay') ?: [];
        $ebayDe = $this->channelReport($listingRows, ['ebay_de', 'ebay']);
        $ebayFr = $this->channelReport($listingRows, ['ebay_fr']);
        $classification = $this->classification($ebayDe['listings']);
        $endedOrStale = str_contains($classification, 'ended/stale') || str_contains($classification, 'not_found');
        $resolverEbay = ['has_link' => $resolverRow['has_link'] ?? false, 'url' => $resolverRow['url'] ?? null, 'is_active' => $resolverRow['is_active'] ?? false, 'icon' => $resolverRow['icon'] ?? null, 'display_icon' => $resolverRow['display_icon'] ?? null, 'reason' => $resolverRow['reason'] ?? null, 'title' => $resolverRow['title'] ?? null];

        if ($endedOrStale) {
            $resolverEbay['is_active'] = false;
            $resolverEbay['icon'] = 'x';
            $resolverEbay['display_icon'] = '✕';
            $resolverEbay['reason'] = collect($listingRows)->contains(fn ($row) => ($row['api']['end_date_is_past'] ?? false) === true) ? 'ebay_end_date_in_past' : 'ebay_ended_stale';
            $resolverEbay['title'] = 'Oferta eBay nieaktywna: '.$resolverEbay['reason'];
        } elseif ($this->hasUnavailableButNotEnded($ebayDe['listings'])) {
            $resolverEbay['is_active'] = false;
            $resolverEbay['icon'] = 'x';
            $resolverEbay['display_icon'] = '✕';
            $resolverEbay['reason'] = 'ebay_unavailable_but_not_ended_needs_review';
            $resolverEbay['title'] = 'Status eBay wymaga weryfikacji: '.$resolverEbay['reason'];
        }

        $diagnosticListing = $ebayDe['preferred'] ?? null;

        return ['part' => ['id' => $part->id, 'sku' => $part->sku, 'part_number' => $part->part_number, 'status' => $part->status, 'quantity' => $part->quantity, 'adminLocalAvailability' => $part->adminLocalAvailability()], 'marketplace_listings' => $listingRows, 'public_item_id' => $diagnosticListing['public_item_id'] ?? null, 'seller_offer_id' => $diagnosticListing['seller_offer_id'] ?? null, 'seller_listing_id' => $diagnosticListing['seller_listing_id'] ?? null, 'requested_listing_id' => $diagnosticListing['requested_listing_id'] ?? null, 'offer_listing_id' => $diagnosticListing['offer_listing_id'] ?? null, 'seller_listing_id_matches_public_item_id' => $diagnosticListing['seller_listing_id_matches_public_item_id'] ?? null, 'public_item_end_date' => $diagnosticListing['public_item_end_date'] ?? null, 'public_item_end_date_source' => $diagnosticListing['public_item_end_date_source'] ?? 'unavailable', 'public_item_end_past' => $diagnosticListing['public_item_end_past'] ?? null, 'seller_listing_status' => $diagnosticListing['seller_listing_status'] ?? null, 'seller_offer_status' => $diagnosticListing['seller_offer_status'] ?? null, 'seller_verification_reason' => $diagnosticListing['seller_verification_reason'] ?? null, 'ebay_de_status' => $ebayDe['status'], 'ebay_de_url' => $ebayDe['url'], 'ebay_fr_status' => $ebayFr['status'], 'ebay_fr_url' => $ebayFr['url'], 'ebay_overall' => $classification, 'needs_ebay_de_publish' => $this->needsEbayDePublishFlag($part, $ebayDe['status'], $ebayDe['listings'], $endedOrStale), 'resolver_ebay' => $resolverEbay, 'duplicate_guard_would_block' => ($endedOrStale || $this->hasUnavailableButNotEnded($ebayDe['listings'])) ? false : collect($ebayDe['listings'])->contains('duplicate_guard_would_block', true), 'audit_classification' => $classification];
    }


    private function channelReport(array $listingRows, array $marketplaces): array
    {
        $rows = collect($listingRows)->filter(fn ($row): bool => in_array((string) ($row['marketplace'] ?? ''), $marketplaces, true))->values();
        $active = $rows->first(fn ($row): bool => ! $this->rowIsEndedOrStale($row) && $this->rowIsActive($row));
        $preferred = $active ?: $rows->first(fn ($row): bool => $this->rowIsEndedOrStale($row)) ?: $rows->first();

        return [
            'status' => $rows->isEmpty() ? 'missing' : ($active ? $this->activeStatus($active) : ($this->hasUnavailableButNotEnded($rows->all()) ? 'unavailable_not_ended_needs_review' : ($preferred && $this->rowIsEndedOrStale($preferred) ? $this->endedStatus($preferred) : 'active_state_uncertain'))),
            'url' => $preferred['url'] ?? null,
            'listings' => $rows->all(),
            'preferred' => $preferred,
        ];
    }


    private function activeStatus(array $row): string
    {
        return data_get($row, 'api.api_listing_status') === 'active_seller_verified' ? 'active_seller_verified' : 'active';
    }

    private function apiNeedsSellerSideVerification(array $api): bool
    {
        $apiStatus = strtolower((string) ($api['api_listing_status'] ?? ''));
        $availability = strtoupper((string) ($api['availability_status'] ?? ''));

        return ! (bool) ($api['end_date_is_past'] ?? false)
            && ($apiStatus === 'unavailable' || ($apiStatus === 'inactive' && $availability === 'UNAVAILABLE'));
    }

    private function sellerSideApiStatus(MarketplaceListing $listing): array
    {
        $account = $listing->account ?: MarketplaceAccount::query()->where('code', $listing->marketplace)->orWhere('marketplace', $listing->marketplace)->first();
        if (! $account) return ['ok' => false, 'read_only' => true, 'blocker' => 'missing_marketplace_account'];
        $sku = (string) ($listing->external_inventory_id ?: $listing->sku ?: $listing->part?->sku ?: '');
        if (blank($sku) && blank($listing->external_offer_id)) return ['ok' => false, 'read_only' => true, 'blocker' => 'missing_offer_id_or_inventory_sku'];

        try {
            return (new EbayApiClient($listing->marketplace, $account))->readOnlyInventoryOfferListingDiagnostics($sku, $listing->external_offer_id, $listing->external_listing_id);
        } catch (\Throwable $e) {
            return ['ok' => false, 'read_only' => true, 'blocker' => 'seller_side_api_error', 'error' => $e->getMessage(), 'exception' => $e::class];
        }
    }

    private function sellerSideConfirmsActive(array $sellerSide, ?string $publicItemId = null): bool
    {
        if (! $this->sellerListingMatchesPublicItem($sellerSide, $publicItemId)) return false;

        $offerStatus = strtoupper((string) ($sellerSide['offer_status'] ?? ''));
        $listingStatus = strtoupper((string) ($sellerSide['listing_status'] ?? ''));

        return in_array($offerStatus, ['PUBLISHED', 'ACTIVE'], true)
            || in_array($listingStatus, ['ACTIVE', 'PUBLICLY_READABLE'], true)
            || (($sellerSide['offer_exists'] ?? false) && filled($sellerSide['listing_id'] ?? null) && ! in_array($offerStatus, ['ENDED', 'UNPUBLISHED', 'DELETED'], true));
    }

    private function realSellerListingId(array $sellerSide): ?string
    {
        if (filled($sellerSide['offer_listing_id'] ?? null)) return (string) $sellerSide['offer_listing_id'];

        $hasSellerProof = filled($sellerSide['offer_id'] ?? null)
            || filled($sellerSide['offer_status'] ?? null)
            || filled($sellerSide['listing_status'] ?? null)
            || (bool) ($sellerSide['offer_exists'] ?? false);

        return $hasSellerProof && filled($sellerSide['listing_id'] ?? null) ? (string) $sellerSide['listing_id'] : null;
    }

    private function sellerListingMatchesPublicItem(array $sellerSide, ?string $publicItemId): bool
    {
        $publicItemId = $this->digitsOnly($publicItemId);
        if ($publicItemId === null) return false;

        $offerListingId = $this->digitsOnly($sellerSide['offer_listing_id'] ?? null);
        if ($offerListingId !== null) return $offerListingId === $publicItemId;

        $requestedListingId = $this->digitsOnly($sellerSide['requested_listing_id'] ?? null);
        $sellerListingId = $this->digitsOnly($sellerSide['listing_id'] ?? null);

        return $sellerListingId !== null && ($requestedListingId === null || $requestedListingId !== $sellerListingId) && $sellerListingId === $publicItemId;
    }

    private function needsEbayDePublishFlag(Part $part, string $ebayDeStatus, array $listingRows, bool $endedOrStale): ?bool
    {
        if ($this->hasUnavailableButNotEndedWithoutEndDate($listingRows)) return null;

        return $endedOrStale && $this->needsEbayDePublish($part, $ebayDeStatus);
    }

    private function needsEbayDePublish(Part $part, string $ebayDeStatus): bool
    {
        return in_array($part->status, ['ready'], true) && (int) $part->quantity > 0 && $part->adminLocalAvailability() !== 'sold' && ! in_array($ebayDeStatus, ['active', 'active_seller_verified', 'unavailable_not_ended_needs_review', 'manual_review_public_status_required'], true);
    }

    private function rowIsActive(array $row): bool
    {
        $status = strtolower((string) ($row['status'] ?? ''));
        $apiStatus = strtolower((string) data_get($row, 'api.api_listing_status', $row['last_api_status'] ?? ''));

        return in_array($status, ['active', 'published', 'live'], true) && ! in_array($apiStatus, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'unavailable', 'failed', 'error'], true);
    }

    private function rowIsEndedOrStale(array $row): bool
    {
        $status = strtolower((string) ($row['status'] ?? ''));
        $apiStatus = strtolower((string) data_get($row, 'api.api_listing_status', $row['last_api_status'] ?? ''));

        return (bool) data_get($row, 'api.end_date_is_past', false) || in_array($status, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'unavailable'], true) || in_array($apiStatus, ['ended', 'deleted', 'archived', 'not_found'], true);
    }

    private function endedStatus(array $row): string
    {
        $apiStatus = strtolower((string) data_get($row, 'api.api_listing_status', ''));
        return in_array($apiStatus, ['not_found', 'ended'], true) ? $apiStatus : 'ended';
    }

    private function classification(array $listingRows): string
    {
        if (collect($listingRows)->contains(fn ($row) => ($row['api']['end_date_is_past'] ?? false) === true)) return 'ended/stale should_show_x_and_allow_new_publish';

        $apiStatuses = collect($listingRows)->pluck('api.api_listing_status')->filter()->values()->all();

        if (in_array('active_seller_verified', $apiStatuses, true)) return 'active_seller_verified active OK';
        if (in_array('active', $apiStatuses, true)) return 'active OK';
        if (in_array('not_found', $apiStatuses, true)) return 'not_found should_show_x_and_allow_new_publish';
        if (in_array('ended', $apiStatuses, true)) return 'ended/stale should_show_x_and_allow_new_publish';
        if ($this->hasUnavailableButNotEndedWithoutEndDate($listingRows)) return 'manual_review_public_status_required needs_review needs_manual_review_public_ended_unknown_api';
        if ($this->hasUnavailableButNotEnded($listingRows)) return 'ebay_unavailable_but_not_ended_needs_review needs_review';
        if (in_array('error', $apiStatuses, true)) return 'api_error needs_review';

        return 'local_only needs_review';
    }

    private function hasUnavailableButNotEnded(array $listingRows): bool
    {
        return collect($listingRows)->contains(function ($row): bool {
            $apiStatus = strtolower((string) data_get($row, 'api.api_listing_status', ''));
            $availability = strtoupper((string) data_get($row, 'api.availability_status', ''));

            return ! (bool) data_get($row, 'api.end_date_is_past', false)
                && (in_array($apiStatus, ['unavailable', 'active_state_uncertain'], true) || ($apiStatus === 'inactive' && $availability === 'UNAVAILABLE'));
        });
    }

    private function hasUnavailableButNotEndedWithoutEndDate(array $listingRows): bool
    {
        return $this->hasUnavailableButNotEnded($listingRows)
            && collect($listingRows)->contains(fn ($row): bool => ($row['public_item_end_date_source'] ?? data_get($row, 'api.end_date_source')) === 'unavailable');
    }

    private function apiIsCertainEndedForHistory(array $api): bool
    {
        $apiStatus = strtolower((string) ($api['api_listing_status'] ?? ''));

        return ($api['end_date_is_past'] ?? false) === true || in_array($apiStatus, ['ended', 'not_found'], true);
    }

    private function apiStatus(MarketplaceListing $listing): array
    {
        $itemId = $this->publicItemId($listing) ?: $this->itemId($listing);
        if (! $itemId || ! ctype_digit($itemId)) return ['api_listing_status' => 'not_checked', 'error' => 'missing_numeric_item_id'];
        $account = $listing->account ?: MarketplaceAccount::query()->where('code', $listing->marketplace)->orWhere('marketplace', $listing->marketplace)->first();
        if (! $account) return ['api_listing_status' => 'error', 'error' => 'missing_marketplace_account', 'item_id' => $itemId];
        $marketplaceId = $listing->marketplace === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE';
        try { return (new EbayApiClient($listing->marketplace, $account))->getListingStatusByItemId($itemId, $marketplaceId); }
        catch (\Throwable $e) { return ['api_listing_status' => 'error', 'item_id' => $itemId, 'error' => $e->getMessage(), 'exception' => $e::class]; }
    }

    private function isBlockingDuplicate(MarketplaceListing $listing, array $api = []): bool
    {
        if ($this->apiIsCertainEndedForHistory($api)) return false;
        if ($this->apiNeedsSellerSideVerification($api) && ! ($api['seller_side_verified_active'] ?? false)) return false;
        $status = strtolower((string) $listing->status); $lastApi = strtolower((string) $listing->last_api_status);
        return in_array($status, ['active', 'published', 'live'], true) && ! in_array($lastApi, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'unavailable', 'failed', 'error'], true) && $listing->not_seen_in_active_api_at === null && (filled($listing->external_listing_id) || filled($listing->external_offer_id));
    }

    private function publicItemId(MarketplaceListing $listing): ?string { if (preg_match('#/itm/(\d+)#', (string) $listing->url, $m)) return $m[1]; return $this->digitsOnly($listing->external_listing_id); }
    private function itemId(MarketplaceListing $listing): ?string { if ($id = $this->publicItemId($listing)) return $id; foreach ([$listing->external_listing_id, $listing->external_offer_id, data_get($listing->raw_payload, 'ebay.item_id'), data_get($listing->raw_payload, 'item_id')] as $id) if (filled($id)) return (string) $id; return null; }
    private function digitsOnly(mixed $value): ?string { if (! filled($value)) return null; return preg_match('/\d+/', (string) $value, $m) ? $m[0] : null; }
    private function rawPayloadDiagnostics(MarketplaceListing $listing, array $api): array
    {
        $raw = is_array($listing->raw_payload) ? $listing->raw_payload : [];
        $dateKeys = ['itemEndDate', 'endDate', 'end_date', 'listingEndDate', 'ended_at', 'api.itemEndDate', 'api.endDate', 'browse.itemEndDate', 'browse.endDate', 'listing_snapshot.itemEndDate', 'listing_snapshot.endDate'];
        $contains = [];
        foreach ($dateKeys as $key) $contains[$key] = filled(data_get($raw, $key));

        return [
            'raw_payload_keys' => array_values(array_map('strval', array_keys($raw))),
            'date_key_presence' => $contains,
            'contains_any_end_date' => in_array(true, $contains, true),
            'extracted_end_date' => $api['end_date'] ?? null,
            'end_date_source' => $api['end_date_source'] ?? 'unavailable',
            'message' => in_array(true, $contains, true) ? null : 'raw_payload does not contain itemEndDate/endDate/end_date/listingEndDate',
        ];
    }

    private function withLocalEndDateFallback(MarketplaceListing $listing, array $api): array { if (filled($api['end_date'] ?? null)) { $api['end_date_source'] = $api['end_date_source'] ?? 'browse_api'; return $api; } $endDate = data_get($listing->raw_payload, 'itemEndDate') ?? data_get($listing->raw_payload, 'endDate') ?? data_get($listing->raw_payload, 'end_date') ?? data_get($listing->raw_payload, 'listingEndDate') ?? data_get($listing->raw_payload, 'ended_at') ?? data_get($listing->raw_payload, 'api.itemEndDate') ?? data_get($listing->raw_payload, 'api.endDate') ?? data_get($listing->raw_payload, 'browse.itemEndDate') ?? data_get($listing->raw_payload, 'browse.endDate') ?? data_get($listing->raw_payload, 'listing_snapshot.itemEndDate') ?? data_get($listing->raw_payload, 'listing_snapshot.endDate'); if (! filled($endDate)) { $api['end_date_source'] = $api['end_date_source'] ?? 'unavailable'; return $api; } $api['end_date'] = (string) $endDate; $api['end_date_source'] = 'local_raw_payload'; $api['end_date_is_past'] = strtotime((string) $endDate) !== false && strtotime((string) $endDate) < now()->timestamp; return $api; }
    private function partIds(Request $request): array { $raw = $request->input('part_ids', $request->input('part_id', '')); return collect(is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw))->filter()->map(fn ($v) => (int) $v)->filter()->unique()->values()->all(); }
    private function summary(array $rows): array { $counts = collect($rows)->countBy('audit_classification')->all(); return $counts + ['total_parts' => count($rows), 'active_OK' => collect($rows)->filter(fn ($r) => str_contains($r['audit_classification'], 'active OK'))->count(), 'ended_stale' => collect($rows)->filter(fn ($r) => str_contains($r['audit_classification'], 'ended/stale'))->count(), 'not_found' => collect($rows)->filter(fn ($r) => str_contains($r['audit_classification'], 'not_found'))->count(), 'api_error' => collect($rows)->filter(fn ($r) => str_contains($r['audit_classification'], 'api_error'))->count(), 'needs_review' => collect($rows)->filter(fn ($r) => str_contains($r['audit_classification'], 'needs_review'))->count(), 'duplicate_guard_would_block' => collect($rows)->where('duplicate_guard_would_block', true)->count()]; }
}

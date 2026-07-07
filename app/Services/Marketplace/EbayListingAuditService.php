<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;

class EbayListingAuditService
{
    private const CUTOFF = '2026-06-01 00:00:00';

    /** @return array<string,mixed> */
    public function run(string $channel = 'ebay_de', int $limit = 100, int $offset = 0, ?int $partId = null, bool $apply = false, bool $checkApi = false): array
    {
        abort_unless(in_array($channel, ['ebay_de', 'ebay_fr', 'ebay'], true), 422, 'Supported eBay channel values: ebay_de, ebay_fr, ebay.');
        $query = MarketplaceListing::query()
            ->with('account:id,code,marketplace,api_enabled,api_base_url,api_mode,api_credentials,api_settings')
            ->where('marketplace', $channel)
            ->whereNotNull('url')
            ->whereHas('part', fn ($q) => $q->where('status', 'ready')->where('quantity', '>', 0));
        if ($partId !== null) $query->where('part_id', $partId);

        $all = (clone $query)->get();
        $rows = (clone $query)->orderBy('id')->offset(max(0, $offset))->limit(max(1, min(500, $limit)))->get();
        $applied = 0;
        $apiChecks = [];
        $results = $rows->map(function (MarketplaceListing $listing) use ($apply, $checkApi, &$applied, &$apiChecks): array {
            $apiCheck = $checkApi ? $this->apiCheckListing($listing) : null;
            $publicCheck = $checkApi ? $this->publicCheckListing($listing) : null;
            $row = $this->auditListing($listing, $apiCheck, $publicCheck);
            if ($apiCheck !== null) $apiChecks[] = $apiCheck;
            if ($apply && in_array($row['action'], ['mark_historical_ended', 'mark_historical_not_found'], true)) {
                $raw = $listing->raw_payload ?: [];
                data_set($raw, 'ebay_diagnose_historical', ['marked_at' => now()->toISOString(), 'reason' => $row['action'], 'public_item_id' => $row['url_item_id']]);
                $listing->forceFill(['status' => 'ended', 'last_api_status' => $row['final_panel_status'] === 'not_found' ? 'not_found' : 'ended', 'not_seen_in_active_api_at' => now(), 'raw_payload' => $raw]);
                $listing->save();
                $row['action'] = $row['action'] === 'mark_historical_not_found' ? 'marked_historical_not_found' : 'marked_historical_ended';
                $applied++;
            }
            return $row;
        })->values()->all();

        return [
            'mode' => $apply ? 'apply' : 'dry_run',
            'dry_run' => ! $apply,
            'local_update_only' => $apply,
            'marketplace_write' => false,
            'summary' => array_merge($this->summary($all, $results), ['locally_updated' => $applied]),
            'results' => $results,
            'part_probe' => $partId ? $this->partProbe($channel, $partId, $checkApi, $apiChecks) : null,
            'warnings' => ['Read-only local audit. No eBay write, publish, revise, relist, end, stock, price or order sync is performed. If eBay API status is not present in local raw_payload/meta/last_api_status, status falls back to needs_manual_review.'],
        ];
    }

    /** @return array<string,mixed> */
    public function auditListing(MarketplaceListing $listing, ?array $apiCheck = null, ?array $publicCheck = null): array
    {
        $raw = $listing->raw_payload ?: [];
        $ids = $this->rawIds($raw);
        $urlItemId = $this->itemIdFromUrl($listing->url);
        $resolved = $this->firstFilled([$listing->external_listing_id, $listing->external_offer_id, $this->columnValue($listing, 'external_id'), $urlItemId, ...array_values($ids)]);
        $localStatus = $this->normalizedStatus($listing, $raw);
        $status = $this->finalPanelStatus($localStatus, $apiCheck, $publicCheck);
        $candidate = $status !== 'active' && $apiCheck === null ? $this->newerCandidate($listing) : null;
        $action = $this->action($listing, $resolved, $status, $candidate);

        return [
            'local_part_id' => $listing->part_id,
            'marketplace_listing_id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'channel' => $this->channel($listing),
            'account_code' => $listing->account?->code,
            'marketplace_id' => strtoupper($this->channel($listing)),
            'local_status' => $listing->status,
            'local_panel_status' => $localStatus,
            'api_listing_status' => $apiCheck['api_listing_status'] ?? null,
            'public_listing_status' => $publicCheck['public_listing_status'] ?? null,
            'final_panel_status' => $status,
            'panel_listing_status' => $status,
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'external_id' => $this->columnValue($listing, 'external_id'),
            'url' => $listing->url,
            'sku' => $listing->sku ?: $listing->external_inventory_id,
            'raw_payload_ids' => $ids,
            'created_at' => optional($listing->created_at)->toISOString(),
            'updated_at' => optional($listing->updated_at)->toISOString(),
            'published_at' => $this->dateFrom($raw, ['published_at','publishedAt','startTime','listing.published_at','ebay.published_at','meta.published_at']),
            'ended_at' => $this->dateFrom($raw, ['ended_at','endedAt','endTime','listing.ended_at','ebay.ended_at','meta.ended_at']),
            'raw_status' => $this->firstFilled([$listing->last_api_status, Arr::get($raw, 'status'), Arr::get($raw, 'listing.status'), Arr::get($raw, 'ebay.status'), Arr::get($raw, 'meta.status')]),
            'url_item_id' => $urlItemId,
            'resolved_item_id' => $resolved,
            'old_item_id' => $status === 'active' ? null : ($urlItemId ?: $resolved),
            'old_url' => $status === 'active' ? null : $listing->url,
            'old_status' => $status === 'active' ? null : $status,
            'old_ended_at' => $this->dateFrom($raw, ['ended_at','endedAt','endTime','listing.ended_at','ebay.ended_at','meta.ended_at']),
            'new_item_id' => $candidate['item_id'] ?? null,
            'new_url' => $candidate['url'] ?? null,
            'new_status' => $candidate['status'] ?? null,
            'new_published_at' => $candidate['published_at'] ?? null,
            'match_source' => $candidate['match_source'] ?? null,
            'confidence' => $candidate['confidence'] ?? ($action === 'needs_manual_review' ? 'low' : null),
            'api_check' => $apiCheck,
            'public_check' => $publicCheck,
            'should_panel_show_active' => $status === 'active',
            'final_decision' => $publicCheck['final_decision'] ?? $apiCheck['final_decision'] ?? ($status === 'active' ? 'local_active_without_api_check' : 'local_not_active_without_api_check'),
            'action' => $action,
        ];
    }

    private function summary($all, array $results): array
    {
        return [
            'total_eBay_de_listings' => $all->count(), 'listings_with_url' => $all->whereNotNull('url')->count(), 'listings_missing_url' => $all->whereNull('url')->count(),
            'listings_with_numeric_item_id' => $all->filter(fn($l) => preg_match('/^\d+$/', (string) $this->firstFilled([$l->external_listing_id, $l->external_offer_id, $this->itemIdFromUrl($l->url)])) === 1)->count(),
            'listings_with_gpsw_external_id' => $all->filter(fn($l) => $this->isGpsw($l->external_offer_id) || $this->isGpsw($l->external_listing_id))->count(),
            'suspected_stale_ended_listings' => collect($results)->whereIn('action', ['mark_historical_ended','mark_historical_not_found','marked_historical_ended','marked_historical_not_found'])->count(),
            'confirmed_active_listings' => collect($results)->where('panel_listing_status', 'active')->count(),
            'confirmed_ended_listings' => collect($results)->where('panel_listing_status', 'ended')->count(),
            'needs_manual_review' => collect($results)->where('action', 'needs_manual_review')->count(),
            'candidates_for_local_fix_high_confidence' => collect($results)->where('action', 'would_update_url')->where('confidence', 'high')->count(),
        ];
    }

    private function action(MarketplaceListing $l, ?string $id, string $status, ?array $candidate): string
    {
        if ($status === 'api_public_check_error') return 'api_public_check_error';
        if ($status === 'api_not_active') return 'needs_review';
        if ($this->isGpsw($l->external_offer_id) || $this->isGpsw($l->external_listing_id) || ($id !== null && ! ctype_digit($id))) return 'invalid_external_id';
        if ($candidate && ($candidate['confidence'] ?? null) === 'high') return 'would_update_url';
        if ($status === 'not_found') return 'mark_historical_not_found';
        if ($status === 'ended') return 'mark_historical_ended';
        if ($status === 'unknown') return 'needs_manual_review';
        return 'ok_active';
    }

    private function newerCandidate(MarketplaceListing $listing): ?array
    {
        if (! $listing->part_id) return null;
        $q = MarketplaceListing::query()->where('marketplace', $listing->marketplace)->where('part_id', $listing->part_id)->whereKeyNot($listing->id)->where('created_at', '>=', self::CUTOFF);
        if ($listing->sku) $q->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$listing->sku]);
        $candidate = $q->get()->first(fn($l) => $this->normalizedStatus($l, $l->raw_payload ?: []) === 'active' && ($this->itemIdFromUrl($l->url) || ctype_digit((string) ($l->external_listing_id ?: $l->external_offer_id))));
        if (! $candidate) return null;
        $itemId = $this->itemIdFromUrl($candidate->url) ?: $candidate->external_listing_id ?: $candidate->external_offer_id;
        return ['item_id' => $itemId, 'url' => $candidate->url ?: 'https://www.ebay.de/itm/'.$itemId, 'status' => 'active', 'published_at' => $this->dateFrom($candidate->raw_payload ?: [], ['published_at','publishedAt','startTime']), 'match_source' => $candidate->sku && $candidate->sku === $listing->sku ? 'sku' : 'part_id', 'confidence' => $candidate->sku && $candidate->sku === $listing->sku ? 'high' : 'medium'];
    }

    private function normalizedStatus(MarketplaceListing $l, array $raw): string
    {
        if ($this->isGpsw($l->external_offer_id) || $this->isGpsw($l->external_listing_id)) return 'needs_review';
        $s = strtolower((string) $this->firstFilled([$l->last_api_status, $l->status, Arr::get($raw, 'status'), Arr::get($raw, 'listing.status'), Arr::get($raw, 'ebay.status'), Arr::get($raw, 'meta.status')]));
        if (in_array($s, ['active','published','live','active_listing'], true)) return 'active';
        if (str_contains($s, 'end') || in_array($s, ['completed','inactive','deleted','archived','not_found','not found'], true)) return $s === 'not_found' || $s === 'not found' ? 'not_found' : 'ended';
        if ($this->dateIsPast($this->dateFrom($raw, ['ended_at','endedAt','endTime','listing.ended_at','ebay.ended_at','meta.ended_at','itemEndDate','item_end_date','api.end_date','response_summary.itemEndDate']))) return 'ended';
        return 'unknown';
    }

    private function partProbe(string $channel, int $partId, bool $checkApi = false, array $apiChecks = []): array
    { $listings = MarketplaceListing::query()->where('marketplace', $channel)->where('part_id', $partId)->get(); return ['part_id'=>$partId, 'attached_listing_ids'=>$listings->pluck('id')->all(), 'url_389994514100_in_url'=>$listings->contains(fn($l)=>str_contains((string)$l->url,'389994514100')), 'item_389994514100_in_ids_or_raw'=>$listings->contains(fn($l)=>str_contains(json_encode([$l->external_listing_id,$l->external_offer_id,$this->columnValue($l,'external_id'),$l->raw_payload]),'389994514100')), 'other_ebay_listings_count'=>max(0,$listings->count()-1), 'recent_publish_logs_count'=>Schema::hasTable('marketplace_sync_logs') ? MarketplaceSyncLog::query()->where('marketplace',$channel)->where('part_id',$partId)->where('created_at','>=',self::CUTOFF)->where('action','like','%publish%')->count() : 0, 'api_checked'=>$checkApi, 'api_checks'=>$apiChecks]; }

    private function apiCheckListing(MarketplaceListing $listing): array
    {
        $itemId = $this->firstFilled([$listing->external_listing_id, $listing->external_offer_id, $this->columnValue($listing, 'external_id'), $this->itemIdFromUrl($listing->url), ...array_values($this->rawIds($listing->raw_payload ?: []))]);
        $marketplaceId = strtoupper($this->channel($listing));
        if ($marketplaceId === 'EBAY_DE' || $listing->marketplace === 'ebay_de') $marketplaceId = 'EBAY_DE';
        if ($marketplaceId === 'EBAY_FR' || $listing->marketplace === 'ebay_fr') $marketplaceId = 'EBAY_FR';
        if (! $itemId || ! ctype_digit($itemId)) return ['checked' => false, 'item_id' => $itemId, 'marketplace_id' => $marketplaceId, 'api_listing_status' => 'not_checked', 'final_decision' => 'requires_manual_review', 'error_message_safe' => 'No numeric eBay itemId/listingId available for read-only API check.'];

        $account = $listing->account ?: MarketplaceAccount::query()->where('code', $listing->marketplace)->orWhere('marketplace', $listing->marketplace)->first();
        if (! $account) return ['checked' => false, 'item_id' => $itemId, 'marketplace_id' => $marketplaceId, 'api_listing_status' => 'not_checked', 'final_decision' => 'requires_manual_review', 'error_message_safe' => 'Marketplace account not found.'];

        $result = (new EbayApiClient($listing->marketplace, $account))->getListingStatusByItemId($itemId, $marketplaceId);
        $apiStatus = (string) ($result['api_listing_status'] ?? 'unknown');
        return $result + [
            'checked' => true,
            'item_id' => $itemId,
            'marketplace_id' => $marketplaceId,
            'api_listing_status' => $apiStatus,
            'exists' => ! in_array($apiStatus, ['not_found', 'unavailable'], true),
            'is_active' => $apiStatus === 'active',
            'is_ended_or_inactive' => in_array($apiStatus, ['ended', 'inactive'], true),
            'final_decision' => $apiStatus === 'active' ? 'api_confirms_active' : 'api_does_not_confirm_active',
        ];
    }

    private function finalPanelStatus(string $localStatus, ?array $apiCheck, ?array $publicCheck = null): string
    {
        if ($apiCheck === null && $publicCheck === null) return $localStatus;
        $api = (string) ($apiCheck['api_listing_status'] ?? 'unknown');
        $public = (string) ($publicCheck['public_listing_status'] ?? 'unknown');
        if ($api === 'active' || $public === 'active') return 'active';
        if (in_array($api, ['not_found'], true) || in_array($public, ['not_found'], true)) return 'not_found';
        if (in_array($api, ['ended', 'inactive'], true) || in_array($public, ['ended', 'inactive'], true)) return 'ended';
        if ($api === 'unavailable') return in_array($public, ['active', 'ended', 'inactive', 'not_found'], true) ? ($public === 'inactive' ? 'ended' : $public) : 'api_public_check_error';
        if (in_array($public, ['error', 'unknown'], true)) return 'api_public_check_error';
        return 'unknown';
    }

    private function publicCheckListing(MarketplaceListing $listing): array
    {
        $itemId = $this->itemIdFromUrl($listing->url);
        if (! $itemId) return ['checked' => false, 'public_listing_status' => 'unknown', 'final_decision' => 'manual_review_public_status_required', 'error_message_safe' => 'No public eBay item id in URL.'];
        $url = $listing->url ?: (($listing->marketplace === 'ebay_fr' ? 'https://www.ebay.fr/itm/' : 'https://www.ebay.de/itm/').$itemId);
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 GPSwiss listing audit'])->timeout(15)->get($url);
            $body = mb_strtolower(substr($response->body(), 0, 250000));
            $status = $this->publicStatusFromHtml($response->status(), $body, $itemId);
            return ['checked' => true, 'url' => $url, 'item_id' => $itemId, 'http_status' => $response->status(), 'public_listing_status' => $status, 'final_decision' => $status === 'active' ? 'public_link_confirms_active' : ($status === 'unknown' ? 'manual_review_public_status_required' : 'public_link_confirms_not_active')];
        } catch (\Throwable $e) {
            return ['checked' => false, 'url' => $url, 'item_id' => $itemId, 'http_status' => null, 'public_listing_status' => 'error', 'final_decision' => 'public_check_error', 'error_message_safe' => $e->getMessage()];
        }
    }

    private function publicStatusFromHtml(int $httpStatus, string $body, string $itemId): string
    {
        if ($httpStatus === 404) return 'not_found';
        if ($httpStatus >= 500 || $body === '') return 'error';
        if (str_contains($body, 'itemid="'.$itemId.'"') || str_contains($body, '/itm/'.$itemId) || str_contains($body, 'itemid\":\"'.$itemId)) {
            foreach (['sofort-kaufen', 'in den warenkorb', 'buy it now', 'add to cart', 'preisvorschlag senden', 'make offer'] as $needle) if (str_contains($body, $needle)) return 'active';
        }
        foreach (['dieses angebot wurde beendet', 'angebot beendet', 'this listing was ended', 'this item is out of stock', 'nicht mehr verfügbar', 'is no longer available', 'objet terminé'] as $needle) if (str_contains($body, $needle)) return 'ended';
        return 'unknown';
    }

    private function rawIds(array $raw): array { return array_filter(['item_id'=>Arr::get($raw,'ebay.item_id') ?? Arr::get($raw,'item_id'), 'listing_id'=>Arr::get($raw,'ebay.listing_id') ?? Arr::get($raw,'listing_id'), 'offer_id'=>Arr::get($raw,'offerId') ?? Arr::get($raw,'offer_id'), 'inventory_id'=>Arr::get($raw,'inventoryItemGroupKey') ?? Arr::get($raw,'inventory_id')], fn($v)=>filled($v)); }
    private function itemIdFromUrl(?string $url): ?string { return preg_match('#/itm/(\d+)#', (string)$url, $m) ? $m[1] : null; }
    private function isGpsw(mixed $v): bool { return preg_match('/^GPSW-\d+$/i', trim((string)$v)) === 1; }
    private function channel(MarketplaceListing $l): string { return $l->account?->code ?: $l->marketplace; }
    private function columnValue(MarketplaceListing $l, string $column): ?string { return Schema::hasColumn('marketplace_listings', $column) ? $this->firstFilled([$l->{$column}]) : null; }
    private function firstFilled(array $values): ?string { foreach ($values as $v) { if (filled($v)) return (string)$v; } return null; }
    private function dateFrom(array $raw, array $keys): ?string { foreach ($keys as $key) { $v = Arr::get($raw, $key); if (filled($v)) return (string)$v; } return null; }
    private function dateIsPast(?string $date): bool { return filled($date) && strtotime((string) $date) !== false && strtotime((string) $date) < now()->timestamp; }
}

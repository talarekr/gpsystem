<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MarketplaceListingImageRefreshService
{
    public const CONFIRM = 'refresh-listing-images';

    public function __construct(private readonly MarketplaceImageSelectionService $images) {}

    public function preview(int $partId, string $channel): array
    {
        $channel = $this->normalizeChannel($channel);
        $part = Part::query()->with('images')->findOrFail($partId);
        $listing = $this->listing($part, $channel);
        $listingDiagnostics = $this->listingDiagnostics($part->id, $channel, $listing);
        $selection = $this->images->selectForPart($part, 0, false, $channel);
        $account = $this->account($listing, $channel);
        $api = $this->apiSnapshot($listing, $account, $channel);
        $request = $this->plannedRequest($listing, $channel, $selection['urls'], $api);

        return [
            'dry_run' => true,
            'part_id' => $part->id,
            'channel' => $channel,
            'marketplace_listing_id' => $listing?->id,
            'external_offer_id' => $listing?->external_offer_id,
            'item_id' => str_starts_with($channel, 'ebay') ? ($listing?->external_listing_id ?: $this->itemIdFromUrl($listing?->url)) : null,
            'resolved_listing_source' => $listing?->raw_payload['image_refresh_resolver_source'] ?? ($listing ? 'existing_local_listing' : null),
            'queried_marketplaces' => $listingDiagnostics['queried_marketplaces'],
            'listings_found_count' => $listingDiagnostics['listings_found_count'],
            'all_candidate_listings' => $listingDiagnostics['all_candidate_listings'],
            'current_database_image_count' => $part->images->count(),
            'eligible_images_count' => $selection['diagnostics']['eligible_images_count'] ?? 0,
            'selected_images_count' => count($selection['urls']),
            'final_marketplace_image_urls' => $selection['urls'],
            'skipped_images_reasons' => $selection['diagnostics']['skipped_images_reasons'] ?? [],
            'skipped_images' => $selection['diagnostics']['skipped_images'] ?? [],
            'local_listing_active' => $this->localActive($listing),
            'api_confirms_active_offer' => $api['api_confirms_active_offer'] ?? false,
            'before_image_count' => $api['before_image_count'] ?? null,
            'planned_request' => $request,
            'api_snapshot' => Arr::except($api, ['raw_offer_payload', 'raw_inventory_payload']),
            'safety_flags' => ['no_relist' => true, 'no_end_offer' => true, 'no_new_offer' => true, 'images_only' => true],
            'blockers' => $this->blockers($listing, $account, $selection['urls'], $api),
        ];
    }

    public function apply(int $partId, string $channel, string $confirm): array
    {
        $preview = $this->preview($partId, $channel);
        if ($confirm !== self::CONFIRM) return $preview + ['ok' => false, 'error' => 'missing_confirm'];
        if (! (bool) config('marketplace.external_api_writes_enabled', false)) return $this->log($preview, ['ok' => false, 'error' => 'marketplace_write_disabled']);
        if (($preview['blockers'] ?? []) !== []) return $this->log($preview, ['ok' => false, 'error' => 'blocked']);

        $listing = MarketplaceListing::findOrFail($preview['marketplace_listing_id']);
        $account = $this->account($listing, $preview['channel']);
        $urls = $preview['final_marketplace_image_urls'];

        if ($preview['channel'] === 'allegro_main') {
            $offerId = (string) $listing->external_offer_id;
            $result = (new AllegroApiClient('allegro_main', $account))->updateProductOfferImages($offerId, $urls);
        } else {
            $offer = $preview['api_snapshot']['offer_payload_for_revise'] ?? [];
            $inventory = $preview['api_snapshot']['inventory_payload_for_revise'] ?? [];
            data_set($inventory, 'product.imageUrls', $urls);
            $result = (new EbayApiClient($preview['channel'], $account))->reviseInventoryOffer((string) ($listing->external_inventory_id ?: $listing->sku), (string) $listing->external_offer_id, $inventory, $offer);
        }

        return $this->log($preview, $result);
    }

    private function log(array $preview, array $result): array
    {
        MarketplaceSyncLog::query()->create(['marketplace' => str_starts_with($preview['channel'], 'allegro') ? 'allegro' : 'ebay', 'marketplace_listing_id' => $preview['marketplace_listing_id'], 'part_id' => $preview['part_id'], 'action' => 'marketplace_listing_image_refresh', 'status' => ($result['ok'] ?? false) ? 'success' : 'error', 'http_status' => $result['http_status'] ?? null, 'request_id' => $result['request_id'] ?? null, 'external_id' => $preview['external_offer_id'] ?: $preview['item_id'], 'message' => ($result['ok'] ?? false) ? 'Existing listing images refreshed.' : 'Existing listing image refresh failed.', 'payload' => ['timestamp' => now()->toISOString(), 'before_image_count' => $preview['before_image_count'], 'after_selected_image_count' => $preview['selected_images_count'], 'request' => $preview['planned_request'], 'response' => Arr::except($result, ['json.access_token', 'access_token']), 'success' => (bool) ($result['ok'] ?? false), 'error' => $result['error'] ?? $result['message'] ?? null], 'created_at' => now()]);
        return $preview + ['dry_run' => false, 'ok' => (bool) ($result['ok'] ?? false), 'apply_result' => $result];
    }

    private function apiSnapshot(?MarketplaceListing $listing, ?MarketplaceAccount $account, string $channel): array
    {
        if (! $listing || ! $account) return ['api_confirms_active_offer' => false];
        if ($channel === 'allegro_main') {
            $r = (new AllegroApiClient('allegro_main', $account))->productOffer((string) $listing->external_offer_id);
            $j = $r['json'] ?? [];
            return ['http_status' => $r['http_status'] ?? null, 'api_confirms_active_offer' => data_get($j, 'publication.status') === 'ACTIVE', 'before_image_count' => is_countable($j['images'] ?? null) ? count($j['images']) : null];
        }
        $r = (new EbayApiClient($channel, $account))->readInventoryOfferAndItem((string) $listing->external_offer_id, (string) ($listing->external_inventory_id ?: $listing->sku));
        $offer = $r['offer_payload'] ?? []; $inv = $r['inventory_payload'] ?? [];
        return $r + ['api_confirms_active_offer' => in_array(($offer['status'] ?? null), ['PUBLISHED', 'PUBLISHED_WITH_WARNINGS'], true) || filled($offer['listingId'] ?? null), 'before_image_count' => is_countable(data_get($inv, 'product.imageUrls')) ? count(data_get($inv, 'product.imageUrls')) : null, 'offer_payload_for_revise' => $offer, 'inventory_payload_for_revise' => $inv];
    }

    private function plannedRequest(?MarketplaceListing $l, string $channel, array $urls, array $api): array { return $channel === 'allegro_main' ? ['method'=>'PATCH','endpoint'=>'/sale/product-offers/{offerId}','payload'=>['images'=>$urls], 'preserves'=>'price,title,stock,VAT,category,description'] : ['method'=>'PUT','endpoints'=>['/sell/inventory/v1/inventory_item/{sku}','/sell/inventory/v1/offer/{offerId}'],'payload_changes_only'=>['inventory_item.product.imageUrls'=>$urls], 'full_payload_preserves'=>'offer price, quantity, title/listingDescription, policies, category']; }
    private function blockers(?MarketplaceListing $l, ?MarketplaceAccount $a, array $urls, array $api): array { return array_values(array_filter([!$l?'missing_listing':null, !$a?'missing_account':null, $urls===[]?'no_eligible_images':null, !$this->localActive($l)?'local_listing_not_active':null, !($api['api_confirms_active_offer']??false)?'api_offer_not_confirmed_active':null])); }
    public function repairEbayMapping(int $partId, string $channel, ?string $publicUrl = null, string $confirm = ''): array
    {
        $channel = $this->normalizeChannel($channel);
        if (! str_starts_with($channel, 'ebay')) return ['ok' => false, 'error' => 'unsupported_channel'];
        if ($confirm !== self::CONFIRM) return ['ok' => false, 'error' => 'missing_confirm'];

        $part = Part::query()->findOrFail($partId);
        $listing = $this->listing($part, $channel, includeInactive: true);
        $account = $this->account($listing, $channel);
        if (! $account) return ['ok' => false, 'error' => 'missing_account'];

        $publicItemId = $this->itemIdFromUrl($publicUrl) ?: $this->itemIdFromUrl($listing?->url) ?: $listing?->external_listing_id;
        $sku = (string) ($listing?->external_inventory_id ?: $listing?->sku ?: $part->sku ?: '');
        $offerId = $listing?->external_offer_id;
        if ($sku === '' && blank($offerId)) return ['ok' => false, 'error' => 'missing_offer_id_or_inventory_sku', 'public_item_id' => $publicItemId];

        $api = (new EbayApiClient($channel, $account))->readOnlyInventoryOfferListingDiagnostics($sku, $offerId, $publicItemId);
        $apiOfferId = $api['offer_id'] ?? $offerId;
        $apiListingId = $api['listing_id'] ?? $publicItemId;
        $active = $this->apiConfirmsActiveEbay($api) && ($publicItemId === null || (string) $apiListingId === (string) $publicItemId || (string) ($api['offer_listing_id'] ?? '') === (string) $publicItemId);
        if (! $active || blank($apiOfferId) || blank($apiListingId)) return ['ok' => false, 'error' => 'api_offer_not_confirmed_active', 'public_item_id' => $publicItemId, 'api_snapshot' => $api];

        $row = DB::transaction(function () use ($listing, $part, $channel, $account, $api, $apiOfferId, $apiListingId, $sku) {
            $payload = [
                'marketplace' => $channel,
                'marketplace_account_id' => $account->id,
                'part_id' => $part->id,
                'external_offer_id' => (string) $apiOfferId,
                'external_listing_id' => (string) $apiListingId,
                'external_inventory_id' => (string) ($api['inventory_sku'] ?? $sku),
                'sku' => (string) ($api['inventory_sku'] ?? $sku),
                'url' => $api['public_item_url'] ?? ('https://www.ebay.de/itm/'.(string) $apiListingId),
                'status' => 'active',
                'sync_status' => 'mapped',
                'match_status' => 'confirmed',
                'match_confidence' => 100,
                'match_reason' => 'admin image refresh repair confirmed by read-only eBay seller API',
                'last_api_status' => 'active',
                'last_error' => null,
                'last_synced_at' => now(),
                'last_seen_at' => now(),
                'not_seen_in_active_api_at' => null,
                'raw_payload' => ['source' => 'admin.marketplace_listing_image_refresh.repair', 'api' => Arr::except($api, ['read_only_ebay_api_responses']), 'image_refresh_resolver_source' => 'repair_action'],
            ];
            $target = $listing ?: MarketplaceListing::query()->where('marketplace', $channel)->where(fn ($q) => $q->where('external_offer_id', $apiOfferId)->orWhere('external_listing_id', $apiListingId))->latest('id')->first();
            if ($target) {
                $target->update($payload);
            } else {
                $target = MarketplaceListing::query()->create($payload);
            }
            return $target->fresh();
        });

        return ['ok' => true, 'marketplace_listing_id' => $row->id, 'public_item_id' => (string) $apiListingId, 'external_offer_id' => (string) $apiOfferId, 'api_snapshot' => $api];
    }


    private function listingDiagnostics(int $partId, string $channel, ?MarketplaceListing $selected): array
    {
        $queriedMarketplaces = $this->queriedMarketplaces($channel);
        $candidates = MarketplaceListing::query()
            ->where('part_id', $partId)
            ->orderByRaw('case when marketplace in ('.implode(',', array_fill(0, count($queriedMarketplaces), '?')).') then 0 else 1 end', $queriedMarketplaces)
            ->latest('updated_at')
            ->latest('id')
            ->get();

        return [
            'queried_marketplaces' => $queriedMarketplaces,
            'listings_found_count' => $candidates->count(),
            'all_candidate_listings' => $candidates->map(fn (MarketplaceListing $listing): array => $this->candidateDiagnostics($listing, $channel, $queriedMarketplaces, $selected))->values()->all(),
        ];
    }

    private function candidateDiagnostics(MarketplaceListing $listing, string $channel, array $queriedMarketplaces, ?MarketplaceListing $selected): array
    {
        $reasons = [];
        $channelMatches = in_array($listing->marketplace, $queriedMarketplaces, true);
        $active = $this->localActive($listing);

        $reasons[] = $channelMatches ? 'accepted_channel_match' : 'rejected_marketplace_not_queried_for_channel';
        $reasons[] = $active ? 'accepted_local_status_active' : 'rejected_local_status_not_active';
        $reasons[] = $selected && $selected->id === $listing->id ? 'selected_for_preview' : 'not_selected_for_preview';

        return [
            'id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'channel' => $listing->marketplace,
            'status' => $listing->status,
            'sync_status' => $listing->sync_status,
            'match_status' => $listing->match_status,
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'external_inventory_id' => $listing->external_inventory_id,
            'sku' => $listing->sku,
            'url' => $listing->url,
            'last_api_status' => $listing->last_api_status,
            'created_at' => $listing->created_at?->toISOString(),
            'updated_at' => $listing->updated_at?->toISOString(),
            'accepted' => $selected && $selected->id === $listing->id,
            'reasons' => $reasons,
        ];
    }

    private function queriedMarketplaces(string $channel): array
    {
        if (str_starts_with($channel, 'allegro')) return ['allegro'];
        return $channel === 'ebay_fr' ? ['ebay_fr'] : ['ebay_de', 'ebay'];
    }

    private function listing(Part $p, string $c, bool $includeInactive = false): ?MarketplaceListing
    {
        if (str_starts_with($c, 'allegro')) return MarketplaceListing::query()->where('part_id', $p->id)->where('marketplace', 'allegro')->latest('id')->first();
        $channels = $this->queriedMarketplaces($c);
        $query = MarketplaceListing::query()->where('part_id', $p->id)->whereIn('marketplace', $channels);
        if (! $includeInactive) {
            $query->where(fn ($q) => $q->whereIn('status', ['published', 'active', 'publication_pending', 'live'])->orWhereIn('last_api_status', ['active', 'published', 'PUBLISHED']));
        }
        return $query->orderByRaw("case when marketplace = ? then 0 when marketplace = 'ebay_de' then 1 else 2 end", [$c])->latest('last_seen_at')->latest('id')->first();
    }
    private function account(?MarketplaceListing $l, string $c): ?MarketplaceAccount { return $l?->account ?: MarketplaceAccount::query()->where('code',$c)->first(); }
    private function normalizeChannel(string $c): string { if (in_array($c, ['allegro','allegro_main'], true)) return 'allegro_main'; return $c === 'ebay_fr' ? 'ebay_fr' : 'ebay_de'; }
    private function localActive(?MarketplaceListing $l): bool { return $l && in_array($l->status, ['published','active','publication_pending','live'], true) && !in_array($l->sync_status, ['ended','withdrawn'], true); }
    private function apiConfirmsActiveEbay(array $api): bool { return in_array((string) ($api['offer_status'] ?? ''), ['PUBLISHED', 'PUBLISHED_WITH_WARNINGS'], true) || (bool) ($api['is_publicly_visible'] ?? false); }
    private function itemIdFromUrl(?string $url): ?string { return is_string($url) && preg_match('~/itm/(\d+)~', $url, $m) ? $m[1] : null; }
}

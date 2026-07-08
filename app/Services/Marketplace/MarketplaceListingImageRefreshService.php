<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Support\Arr;

class MarketplaceListingImageRefreshService
{
    public const CONFIRM = 'refresh-listing-images';

    public function __construct(private readonly MarketplaceImageSelectionService $images) {}

    public function preview(int $partId, string $channel): array
    {
        $channel = $this->normalizeChannel($channel);
        $part = Part::query()->with('images')->findOrFail($partId);
        $listing = $this->listing($part, $channel);
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
            'item_id' => $channel === 'ebay_de' ? ($listing?->external_listing_id ?: $this->itemIdFromUrl($listing?->url)) : null,
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
            $result = (new EbayApiClient('ebay_de', $account))->reviseInventoryOffer((string) $listing->external_inventory_id, (string) $listing->external_offer_id, $inventory, $offer);
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
        $r = (new EbayApiClient('ebay_de', $account))->readInventoryOfferAndItem((string) $listing->external_offer_id, (string) $listing->external_inventory_id);
        $offer = $r['offer_payload'] ?? []; $inv = $r['inventory_payload'] ?? [];
        return $r + ['api_confirms_active_offer' => in_array(($offer['status'] ?? null), ['PUBLISHED', 'PUBLISHED_WITH_WARNINGS'], true) || filled($offer['listingId'] ?? null), 'before_image_count' => is_countable(data_get($inv, 'product.imageUrls')) ? count(data_get($inv, 'product.imageUrls')) : null, 'offer_payload_for_revise' => $offer, 'inventory_payload_for_revise' => $inv];
    }

    private function plannedRequest(?MarketplaceListing $l, string $channel, array $urls, array $api): array { return $channel === 'allegro_main' ? ['method'=>'PATCH','endpoint'=>'/sale/product-offers/{offerId}','payload'=>['images'=>$urls], 'preserves'=>'price,title,stock,VAT,category,description'] : ['method'=>'PUT','endpoints'=>['/sell/inventory/v1/inventory_item/{sku}','/sell/inventory/v1/offer/{offerId}'],'payload_changes_only'=>['inventory_item.product.imageUrls'=>$urls], 'full_payload_preserves'=>'offer price, quantity, title/listingDescription, policies, category']; }
    private function blockers(?MarketplaceListing $l, ?MarketplaceAccount $a, array $urls, array $api): array { return array_values(array_filter([!$l?'missing_listing':null, !$a?'missing_account':null, $urls===[]?'no_eligible_images':null, !$this->localActive($l)?'local_listing_not_active':null, !($api['api_confirms_active_offer']??false)?'api_offer_not_confirmed_active':null])); }
    private function listing(Part $p, string $c): ?MarketplaceListing { $m = str_starts_with($c,'allegro')?'allegro':'ebay'; return MarketplaceListing::query()->where('part_id',$p->id)->where('marketplace',$m)->latest('id')->first(); }
    private function account(?MarketplaceListing $l, string $c): ?MarketplaceAccount { return $l?->account ?: MarketplaceAccount::query()->where('code',$c)->first(); }
    private function normalizeChannel(string $c): string { return in_array($c, ['allegro','allegro_main'], true) ? 'allegro_main' : 'ebay_de'; }
    private function localActive(?MarketplaceListing $l): bool { return $l && in_array($l->status, ['published','active','publication_pending'], true) && !in_array($l->sync_status, ['ended','withdrawn'], true); }
    private function itemIdFromUrl(?string $url): ?string { return is_string($url) && preg_match('~/itm/(\d+)~', $url, $m) ? $m[1] : null; }
}

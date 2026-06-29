<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Services\Marketplace\Api\EbayApiClient;

class EbayPublishAdapter extends BaseMarketplacePublishAdapter
{
    protected function channel(): string { return 'ebay_de'; }
    protected function marketplace(): string { return 'ebay_de'; }
    protected function accountCode(): string { return 'ebay_de'; }

    protected function performLivePublish(Part $part, array $readiness, array $payload, ?MarketplaceAccount $account): array
    {
        if (! $account) return ['ok' => false, 'status' => 'not_configured', 'error' => 'Marketplace account ebay_de is missing.'];
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $policies = $payload['business_policies'] ?? [];
        $sku = (string) ($payload['sku'] ?: $part->sku ?: 'part-'.$part->id);
        $inventory = [
            'product' => ['title' => (string) ($payload['title'] ?? $part->name), 'description' => (string) ($payload['description_rendered_html'] ?? $part->description ?? $part->short_description ?? ''), 'imageUrls' => $payload['image_urls'] ?? [], 'aspects' => $payload['item_specifics'] ?? []],
            'condition' => strtoupper((string) ($settings['condition'] ?? 'USED_EXCELLENT')), 'availability' => ['shipToLocationAvailability' => ['quantity' => (int) ($payload['quantity'] ?? $part->quantity ?? 1)]],
        ];
        $offer = ['sku' => $sku, 'marketplaceId' => (string) ($settings['marketplace_id'] ?? 'EBAY_DE'), 'format' => (string) ($settings['format'] ?? 'FIXED_PRICE'), 'listingDuration' => (string) ($settings['listing_duration'] ?? 'GTC'), 'availableQuantity' => (int) ($payload['quantity'] ?? $part->quantity ?? 1), 'categoryId' => (string) ($payload['category_id'] ?? ''), 'merchantLocationKey' => (string) ($policies['merchant_location_key'] ?? $settings['merchant_location_key'] ?? ''), 'pricingSummary' => ['price' => ['value' => (string) ($payload['price_eur'] ?? $readiness['marketplace_price']), 'currency' => 'EUR']], 'listingPolicies' => ['fulfillmentPolicyId' => (string) ($policies['selected_fulfillment_policy_id'] ?? $settings['fulfillment_policy_id'] ?? ''), 'paymentPolicyId' => (string) ($policies['selected_payment_policy_id'] ?? $settings['payment_policy_id'] ?? ''), 'returnPolicyId' => (string) ($policies['selected_return_policy_id'] ?? $settings['return_policy_id'] ?? '')]];
        $result = (new EbayApiClient('ebay_de', $account))->publishInventoryOffer($sku, $inventory, $offer);
        return ['ok' => $result['ok'] ?? false, 'action' => 'publishOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'listing_id' => $result['listing_id'] ?? null, 'external_inventory_id' => $sku, 'url' => isset($result['listing_id']) ? 'https://www.ebay.de/itm/'.$result['listing_id'] : null, 'request_id' => $result['request_id'] ?? null, 'response_summary' => ['step' => $result['step'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'listing_id' => $result['listing_id'] ?? null], 'json' => $result['json'] ?? [], 'error' => $result['error'] ?? 'eBay publish failed.'];
    }
}

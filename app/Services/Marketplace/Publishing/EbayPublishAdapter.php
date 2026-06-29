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
        $sku = $this->skuFor($part, $payload);
        $payload['sku'] = $sku;
        $missing = [];
        foreach (['category_id' => 'eBay: brakuje categoryId dla wybranej kategorii', 'title' => 'eBay: brakuje title'] as $key => $message) if (blank($payload[$key] ?? null) && ($key !== 'title' || blank($part->name ?? null))) $missing[] = $message;
        foreach (['merchant_location_key' => 'eBay: brakuje merchantLocationKey', 'selected_fulfillment_policy_id' => 'eBay: brakuje fulfillmentPolicyId', 'selected_payment_policy_id' => 'eBay: brakuje paymentPolicyId', 'selected_return_policy_id' => 'eBay: brakuje returnPolicyId'] as $key => $message) if (blank($policies[$key] ?? $this->settingForPolicy($settings, $key))) $missing[] = $message;
        if ($missing !== []) return ['ok' => false, 'status' => 'payload_invalid', 'action' => 'publishOffer', 'error' => implode('; ', $missing), 'request_summary' => $this->requestSummary($payload), 'response_summary' => ['missing' => $missing]];
        $inventory = [
            'product' => ['title' => (string) ($payload['title'] ?? $part->name), 'description' => (string) ($payload['description_rendered_html'] ?? $part->description ?? $part->short_description ?? ''), 'imageUrls' => $payload['image_urls'] ?? [], 'aspects' => $payload['item_specifics'] ?? []],
            'condition' => $this->conditionFromPart($part, $payload, $settings), 'availability' => ['shipToLocationAvailability' => ['quantity' => (int) ($payload['quantity'] ?? $part->quantity ?? 1)]],
        ];
        $offer = ['sku' => $sku, 'marketplaceId' => (string) ($settings['marketplace_id'] ?? 'EBAY_DE'), 'format' => (string) ($settings['format'] ?? 'FIXED_PRICE'), 'listingDuration' => (string) ($settings['listing_duration'] ?? 'GTC'), 'availableQuantity' => (int) ($payload['quantity'] ?? $part->quantity ?? 1), 'categoryId' => (string) ($payload['category_id'] ?? ''), 'merchantLocationKey' => (string) ($policies['merchant_location_key'] ?? $this->settingForPolicy($settings, 'merchant_location_key') ?? ''), 'pricingSummary' => ['price' => ['value' => (string) ($payload['price_eur'] ?? $readiness['marketplace_price']), 'currency' => 'EUR']], 'listingPolicies' => ['fulfillmentPolicyId' => (string) ($policies['selected_fulfillment_policy_id'] ?? $this->settingForPolicy($settings, 'selected_fulfillment_policy_id') ?? ''), 'paymentPolicyId' => (string) ($policies['selected_payment_policy_id'] ?? $this->settingForPolicy($settings, 'selected_payment_policy_id') ?? ''), 'returnPolicyId' => (string) ($policies['selected_return_policy_id'] ?? $this->settingForPolicy($settings, 'selected_return_policy_id') ?? '')]];
        $result = (new EbayApiClient('ebay_de', $account))->publishInventoryOffer($sku, $inventory, $offer);
        return ['ok' => $result['ok'] ?? false, 'action' => 'publishOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'listing_id' => $result['listing_id'] ?? null, 'external_inventory_id' => $sku, 'url' => isset($result['listing_id']) ? 'https://www.ebay.de/itm/'.$result['listing_id'] : null, 'request_id' => $result['request_id'] ?? null, 'response_summary' => ['step' => $result['step'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'listing_id' => $result['listing_id'] ?? null], 'json' => $result['json'] ?? [], 'error' => $this->ebayError($result)];
    }


    private function skuFor(Part $part, array $payload): string
    {
        foreach ([$payload['sku'] ?? null, $part->sku, $part->visible_code ?? null, $part->internal_code ?? null, $part->part_number, $part->manufacturer_code] as $value) {
            if (filled($value)) return (string) $value;
        }
        return 'part-'.$part->id;
    }

    private function settingForPolicy(array $settings, string $key): mixed
    {
        return match ($key) {
            'merchant_location_key' => $this->merchantLocationKey($settings),
            'selected_fulfillment_policy_id' => $settings['fulfillment_policy_id'] ?? null,
            'selected_payment_policy_id' => $settings['payment_policy_id'] ?? null,
            'selected_return_policy_id' => $settings['return_policy_id'] ?? null,
            default => null,
        };
    }

    private function merchantLocationKey(array $settings): ?string
    {
        foreach (['merchant_location_key', 'merchantLocationKey', 'location_key', 'inventory_location_key'] as $key) {
            if (filled($settings[$key] ?? null)) return (string) $settings[$key];
        }

        $defaults = array_merge(
            (array) config('product-hub.ebay.default_location', []),
            (array) config('product-hub.ebay.accounts.'.$this->accountCode(), [])
        );

        foreach (['merchant_location_key', 'merchantLocationKey', 'location_key', 'inventory_location_key'] as $key) {
            if (filled($defaults[$key] ?? null)) return (string) $defaults[$key];
        }

        return null;
    }

    private function conditionFromPart(Part $part, array $payload, array $settings): string
    {
        if (filled($payload['condition'] ?? null)) return strtoupper((string) $payload['condition']);
        $value = mb_strtolower(trim((string) ($part->condition_notes ?? '')));
        $map = ['używany' => 'USED_EXCELLENT', 'uzywany' => 'USED_EXCELLENT', 'używana' => 'USED_EXCELLENT', 'used' => 'USED_EXCELLENT', 'nowy' => 'NEW', 'nowa' => 'NEW', 'new' => 'NEW'];
        return $map[$value] ?? strtoupper((string) ($settings['condition'] ?? 'USED_EXCELLENT'));
    }

    private function ebayError(array $result): string
    {
        if (filled($result['error'] ?? null) && $result['error'] !== 'eBay publish failed.') return (string) $result['error'];
        $json = is_array($result['json'] ?? null) ? $result['json'] : [];
        $first = is_array($json['errors'][0] ?? null) ? $json['errors'][0] : [];
        $message = $first['message'] ?? $first['longMessage'] ?? $json['message'] ?? null;
        return filled($message) ? 'eBay '.($result['step'] ?? 'publish').' failed: '.$message : 'eBay '.($result['step'] ?? 'publish').' failed (HTTP '.($result['http_status'] ?? 'n/a').').';
    }
}

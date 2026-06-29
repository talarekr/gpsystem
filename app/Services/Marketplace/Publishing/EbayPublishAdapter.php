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
        $aspectNormalization = $this->normalizeAspects($payload['item_specifics'] ?? []);
        $inventory = [
            'product' => ['title' => (string) ($payload['title'] ?? $part->name), 'description' => (string) ($payload['description_rendered_html'] ?? $part->description ?? $part->short_description ?? ''), 'imageUrls' => $payload['image_urls'] ?? [], 'aspects' => $aspectNormalization['aspects']],
            'condition' => $this->conditionFromPart($part, $payload, $settings), 'availability' => ['shipToLocationAvailability' => ['quantity' => (int) ($payload['quantity'] ?? $part->quantity ?? 1)]],
        ];
        $merchantLocationKey = (string) ($policies['merchant_location_key'] ?? $this->settingForPolicy($settings, 'merchant_location_key') ?? '');
        $offer = ['sku' => $sku, 'marketplaceId' => (string) ($settings['marketplace_id'] ?? 'EBAY_DE'), 'format' => (string) ($settings['format'] ?? 'FIXED_PRICE'), 'listingDuration' => (string) ($settings['listing_duration'] ?? 'GTC'), 'availableQuantity' => (int) ($payload['quantity'] ?? $part->quantity ?? 1), 'categoryId' => (string) ($payload['category_id'] ?? ''), 'merchantLocationKey' => $merchantLocationKey, 'pricingSummary' => ['price' => ['value' => (string) ($payload['price_eur'] ?? $readiness['marketplace_price']), 'currency' => 'EUR']], 'listingPolicies' => ['fulfillmentPolicyId' => (string) ($policies['selected_fulfillment_policy_id'] ?? $this->settingForPolicy($settings, 'selected_fulfillment_policy_id') ?? ''), 'paymentPolicyId' => (string) ($policies['selected_payment_policy_id'] ?? $this->settingForPolicy($settings, 'selected_payment_policy_id') ?? ''), 'returnPolicyId' => (string) ($policies['selected_return_policy_id'] ?? $this->settingForPolicy($settings, 'selected_return_policy_id') ?? '')]];
        $result = (new EbayApiClient('ebay_de', $account))->publishInventoryOffer($sku, $inventory, $offer);
        return ['ok' => $result['ok'] ?? false, 'action' => 'publishOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'listing_id' => $result['listing_id'] ?? null, 'external_inventory_id' => $sku, 'url' => isset($result['listing_id']) ? 'https://www.ebay.de/itm/'.$result['listing_id'] : null, 'request_id' => $result['request_id'] ?? null, 'request_summary' => $this->requestSummary($payload) + ['resolved_merchant_location_key' => $merchantLocationKey, 'merchantLocationKey' => $offer['merchantLocationKey'], 'aspects_diagnostics' => $aspectNormalization['diagnostics']], 'response_summary' => $this->responseSummary($result), 'json' => $result['json'] ?? [], 'error' => $this->ebayError($result), 'ui_error' => 'marketplace_api_error'];
    }

    /**
     * @return array{aspects: array<string, array<int, string>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function normalizeAspects(mixed $aspects): array
    {
        $normalized = [];
        $diagnostics = [];

        foreach ((array) $aspects as $name => $value) {
            if (! is_string($name) && ! is_int($name)) continue;

            $aspectName = trim((string) $name);
            if ($aspectName === '') continue;

            [$values, $skipped, $originalShape] = $this->normalizeAspectValues($value);
            if ($values !== []) {
                $normalized[$aspectName] = $values;
            }

            $diagnostics[] = [
                'aspect_name' => $aspectName,
                'original_shape' => $originalShape,
                'normalized_shape' => $values === [] ? 'empty' : 'array<string>',
                'skipped' => $skipped || $values === [],
            ];
        }

        return ['aspects' => $normalized, 'diagnostics' => $diagnostics];
    }

    /** @return array{0: array<int, string>, 1: bool, 2: string} */
    private function normalizeAspectValues(mixed $value): array
    {
        $shape = get_debug_type($value);
        $skipped = false;

        if (is_scalar($value)) {
            $string = trim((string) $value);
            return [$string === '' ? [] : [$string], $string === '', $shape];
        }

        if (is_object($value)) {
            foreach (['label', 'value', 'name'] as $key) {
                if (isset($value->{$key}) && is_scalar($value->{$key})) {
                    $string = trim((string) $value->{$key});
                    return [$string === '' ? [] : [$string], $string === '', 'object'];
                }
            }

            return [[], true, 'object'];
        }

        if (is_array($value)) {
            $values = [];
            $isList = array_is_list($value);

            if (! $isList) {
                foreach (['label', 'value', 'name'] as $key) {
                    if (isset($value[$key]) && is_scalar($value[$key])) {
                        $string = trim((string) $value[$key]);
                        return [$string === '' ? [] : [$string], $string === '', 'associative_array'];
                    }
                }
                return [[], true, 'associative_array'];
            }

            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $string = trim((string) $item);
                    if ($string !== '') $values[] = $string;
                    continue;
                }

                if (is_array($item) || is_object($item)) {
                    foreach (['label', 'value', 'name'] as $key) {
                        $nestedValue = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
                        if (is_scalar($nestedValue)) {
                            $string = trim((string) $nestedValue);
                            if ($string !== '') $values[] = $string;
                            continue 2;
                        }
                    }
                }

                $skipped = true;
            }

            return [array_values(array_unique($values)), $skipped, 'array'];
        }

        return [[], true, $shape];
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

    private function responseSummary(array $result): array
    {
        return [
            'step' => $result['step'] ?? null,
            'offer_id' => $result['offer_id'] ?? null,
            'listing_id' => $result['listing_id'] ?? null,
            'correlation_id' => $result['request_id'] ?? null,
            'body' => is_array($result['json'] ?? null) ? $result['json'] : [],
        ];
    }
}

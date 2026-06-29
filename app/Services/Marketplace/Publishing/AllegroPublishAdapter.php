<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;

class AllegroPublishAdapter extends BaseMarketplacePublishAdapter
{
    protected function channel(): string { return 'allegro_main'; }
    protected function marketplace(): string { return 'allegro'; }
    protected function accountCode(): string { return 'allegro_main'; }

    protected function performLivePublish(Part $part, array $readiness, array $payload, ?MarketplaceAccount $account): array
    {
        if (! $account) return ['ok' => false, 'status' => 'not_configured', 'error' => 'Marketplace account allegro_main is missing.'];
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $sku = $this->skuFor($part, $payload);
        $payload['sku'] = $sku;
        $body = array_filter(['name' => (string) ($payload['title'] ?? $part->name), 'category' => ['id' => (string) ($payload['category_id'] ?? '')], 'productSet' => $settings['productSet'] ?? null, 'parameters' => $payload['allegro_offer_parameters'] ?? $payload['allegro_parameters']['offer_parameters'] ?? [], 'images' => array_map(fn ($url) => ['url' => $url], (array) ($payload['image_urls'] ?? [])), 'sellingMode' => $settings['sellingMode'] ?? ['format' => 'BUY_NOW', 'price' => ['amount' => (string) ($payload['price_pln'] ?? $readiness['marketplace_price']), 'currency' => 'PLN']], 'stock' => ['available' => (int) ($payload['quantity'] ?? $part->quantity ?? 1), 'unit' => 'UNIT'], 'publication' => ['status' => 'ACTIVE'], 'delivery' => $settings['delivery'] ?? null, 'payments' => $settings['payments'] ?? null, 'afterSalesServices' => $settings['afterSalesServices'] ?? null, 'location' => $settings['location'] ?? null, 'external' => ['id' => $sku]], fn ($v) => $v !== null && $v !== []);
        $result = (new AllegroApiClient('allegro_main', $account))->createProductOffer($body);
        return ['ok' => $result['ok'] ?? false, 'action' => 'createProductOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'external_listing_id' => $result['offer_id'] ?? null, 'listing_status' => ($result['http_status'] ?? null) === 202 ? 'publication_pending' : 'published', 'request_id' => $result['request_id'] ?? null, 'request_summary' => $this->requestSummary($payload), 'response_summary' => $this->responseSummary($result), 'json' => $result['json'] ?? [], 'error' => 'Allegro product-offers publish failed.'];
    }

    private function skuFor(Part $part, array $payload): string
    {
        foreach ([$payload['sku'] ?? null, $part->sku, $part->visible_code ?? null, $part->internal_code ?? null, $part->part_number, $part->manufacturer_code] as $value) {
            if (filled($value)) return (string) $value;
        }
        return 'part-'.$part->id;
    }

    private function responseSummary(array $result): array
    {
        return [
            'offer_id' => $result['offer_id'] ?? null,
            'operation_location' => $result['operation_location'] ?? null,
            'async' => ($result['http_status'] ?? null) === 202,
            'correlation_id' => $result['request_id'] ?? null,
            'body' => is_array($result['json'] ?? null) ? $result['json'] : [],
        ];
    }
}

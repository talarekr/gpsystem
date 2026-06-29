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
        $client = new AllegroApiClient('allegro_main', $account);
        $salesSettings = $client->resolveSalesSettingsByName((string) ($settings['sales_settings_name'] ?? 'GPSWISS'));
        $delivery = $this->deliverySettings($settings, $salesSettings);
        $afterSales = $this->afterSalesSettings($settings, $salesSettings);
        $body = array_filter(['name' => (string) ($payload['title'] ?? $part->name), 'category' => ['id' => (string) ($payload['category_id'] ?? '')], 'productSet' => $settings['productSet'] ?? null, 'parameters' => $payload['allegro_offer_parameters'] ?? $payload['allegro_parameters']['offer_parameters'] ?? [], 'images' => $this->normalizeImageUrls($payload['image_urls'] ?? []), 'sellingMode' => $settings['sellingMode'] ?? ['format' => 'BUY_NOW', 'price' => ['amount' => (string) ($payload['price_pln'] ?? $readiness['marketplace_price']), 'currency' => 'PLN']], 'stock' => ['available' => (int) ($payload['quantity'] ?? $part->quantity ?? 1), 'unit' => 'UNIT'], 'publication' => ['status' => 'ACTIVE'], 'delivery' => $delivery, 'payments' => $settings['payments'] ?? null, 'afterSalesServices' => $afterSales, 'location' => $settings['location'] ?? null, 'external' => ['id' => $sku]], fn ($v) => $v !== null && $v !== []);
        $result = $client->createProductOffer($body);
        return ['ok' => $result['ok'] ?? false, 'action' => 'createProductOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'external_listing_id' => $result['offer_id'] ?? null, 'listing_status' => ($result['http_status'] ?? null) === 202 ? 'publication_pending' : 'published', 'request_id' => $result['request_id'] ?? null, 'request_summary' => $this->requestSummary($payload, $body) + ['allegro_sales_settings' => $this->salesSettingsSummary($salesSettings)], 'response_summary' => $this->responseSummary($result), 'json' => $result['json'] ?? [], 'error' => 'Allegro product-offers publish failed.', 'ui_error' => 'Uzupełnij ustawienia sprzedaży Allegro GPSWISS. Szczegóły są w Logach.'];
    }


    private function deliverySettings(array $settings, array $salesSettings): ?array
    {
        $delivery = is_array($settings['delivery'] ?? null) ? $settings['delivery'] : [];
        $id = $salesSettings['shippingRates']['id'] ?? data_get($delivery, 'shippingRates.id');
        if (blank($id)) return $delivery ?: null;
        $delivery['shippingRates'] = ['id' => (string) $id];
        return $delivery;
    }

    private function afterSalesSettings(array $settings, array $salesSettings): ?array
    {
        $afterSales = is_array($settings['afterSalesServices'] ?? null) ? $settings['afterSalesServices'] : [];
        foreach (['returnPolicy', 'impliedWarranty', 'warranty'] as $key) {
            $id = $salesSettings[$key]['id'] ?? data_get($afterSales, $key.'.id');
            if (filled($id)) $afterSales[$key] = ['id' => (string) $id];
        }
        return $afterSales ?: null;
    }

    private function salesSettingsSummary(array $salesSettings): array
    {
        return collect($salesSettings)->map(fn (array $row) => [
            'searched_name' => $row['searched_name'] ?? 'GPSWISS',
            'id' => $row['id'] ?? null,
            'found' => (bool) ($row['found'] ?? false),
            'reason' => $row['reason'] ?? null,
        ])->all();
    }

    /** @return array<int, string> */
    private function normalizeImageUrls(mixed $images): array
    {
        return array_values(array_filter(array_map(function (mixed $image): ?string {
            $url = null;
            if (is_string($image)) $url = $image;
            if (is_array($image) && is_string($image['url'] ?? null)) $url = $image['url'];
            if (is_object($image) && is_string($image->url ?? null)) $url = $image->url;
            $url = trim((string) $url);
            return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && (string) parse_url($url, PHP_URL_HOST) !== '' ? $url : null;
        }, (array) $images)));
    }

    protected function requestSummary(array $payload, ?array $body = null): array
    {
        $summary = parent::requestSummary($payload);
        $images = is_array($body) ? ($body['images'] ?? []) : $this->normalizeImageUrls($payload['image_urls'] ?? []);
        $first = is_array($images) ? ($images[0] ?? null) : null;

        return $summary + [
            'images_count' => is_array($images) ? count($images) : 0,
            'images_shape' => $this->imagesShape($images),
            'first_image_type' => $first === null ? null : get_debug_type($first),
        ];
    }

    private function imagesShape(mixed $images): string
    {
        if (! is_array($images)) return get_debug_type($images);
        if ($images === []) return 'empty';
        foreach ($images as $image) {
            if (! is_string($image)) return 'mixed';
        }
        return 'strings';
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

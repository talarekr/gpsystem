<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\AllegroSalesSettingsResolver;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use App\Services\Marketplace\MarketplacePublishGate;

class AllegroPublishAdapter extends BaseMarketplacePublishAdapter
{
    private const DEFAULT_SAFETY_INFORMATION = 'Część używana pochodząca z demontażu pojazdu. Montaż powinien zostać wykonany przez wykwalifikowany warsztat lub osobę posiadającą odpowiednią wiedzę techniczną. Przed montażem należy porównać numer części i zgodność z pojazdem. Produkt nie jest zabawką.';
    public function __construct(MarketplaceListingReadinessService $readinessService, MarketplacePublishGate $gate, ApiIntegrationLogger $logger, private readonly AllegroSalesSettingsResolver $allegroSalesSettingsResolver)
    {
        parent::__construct($readinessService, $gate, $logger);
    }
    protected function channel(): string { return 'allegro_main'; }
    protected function marketplace(): string { return 'allegro'; }
    protected function accountCode(): string { return 'allegro_main'; }

    protected function performLivePublish(Part $part, array $readiness, array $payload, ?MarketplaceAccount $account): array
    {
        if (! $account) return ['ok' => false, 'status' => 'not_configured', 'error' => 'Marketplace account allegro_main is missing.'];
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $sku = $this->skuFor($part, $payload);
        $payload['sku'] = $sku;
        $salesSettings = $this->allegroSalesSettingsResolver->resolve($account, $part->allegro_shipping_rate_name ?? null);
        if (($salesSettings['blockers'] ?? []) !== []) return ['ok' => false, 'status' => 'blocked_sales_settings', 'errors' => $salesSettings['blockers'], 'request_summary' => ['allegro_sales_settings' => $salesSettings], 'write' => false];
        $delivery = $this->deliverySettings($settings, $salesSettings);
        $afterSales = $this->afterSalesSettings($settings, $salesSettings);
        $client = new AllegroApiClient('allegro_main', $account);
        $offerImages = $this->normalizeImageUrls($payload['image_urls'] ?? $payload['images'] ?? []);
        $description = $this->descriptionPayload($part, $payload, $offerImages);
        $productNameDiagnostics = $this->productNameDiagnostics($part, $payload);
        if (($productNameDiagnostics['product_name_length'] ?? 0) < 12) return ['ok' => false, 'status' => 'blocked_product_name_too_short', 'errors' => ['allegro_product_name_too_short'], 'request_summary' => $productNameDiagnostics, 'write' => false];
        $productSet = $this->productSetPayload($settings, $payload, $productNameDiagnostics['product_name'], $offerImages[0] ?? null);
        $offerParameters = $this->offerParametersPayload($payload, $productSet);
        $body = array_filter(['name' => (string) ($payload['title'] ?? $part->name), 'category' => ['id' => (string) ($payload['category_id'] ?? '')], 'productSet' => $productSet, 'parameters' => $offerParameters, 'images' => $offerImages, 'description' => $description, 'sellingMode' => $settings['sellingMode'] ?? ['format' => 'BUY_NOW', 'price' => ['amount' => (string) ($payload['price_pln'] ?? $readiness['marketplace_price']), 'currency' => 'PLN']], 'stock' => ['available' => (int) ($payload['quantity'] ?? $part->quantity ?? 1), 'unit' => 'UNIT'], 'publication' => ['status' => 'ACTIVE'], 'delivery' => $delivery, 'payments' => $this->paymentsPayload($settings['payments'] ?? null, $payload['payments'] ?? null), 'afterSalesServices' => $afterSales, 'location' => $settings['location'] ?? null, 'external' => ['id' => $sku]], fn ($v) => $v !== null && $v !== []);
        $result = $client->createProductOffer($body);
        return ['ok' => $result['ok'] ?? false, 'action' => 'createProductOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'external_listing_id' => $result['offer_id'] ?? null, 'listing_status' => ($result['http_status'] ?? null) === 202 ? 'publication_pending' : 'published', 'request_id' => $result['request_id'] ?? null, 'request_summary' => $this->requestSummary($payload, $body) + $productNameDiagnostics + ['allegro_sales_settings' => $this->salesSettingsSummary($salesSettings)], 'response_summary' => $this->responseSummary($result), 'json' => $result['json'] ?? [], 'error' => 'Allegro product-offers publish failed.', 'ui_error' => 'Uzupełnij ustawienia sprzedaży Allegro GPSWISS. Szczegóły są w Logach.'];
    }

    /** @return array<string, mixed> */
    private function productNameDiagnostics(Part $part, array $payload): array
    {
        $partTitle = trim((string) (($payload['title'] ?? null) ?: ($part->name ?? '')));
        $mainPartCode = trim((string) (($payload['part_number'] ?? null) ?: ($part->part_number ?? null) ?: ($part->oem_number ?? null) ?: ($part->manufacturer_code ?? null) ?: ($payload['sku'] ?? null) ?: ($part->sku ?? '')));
        $productName = $partTitle;
        $source = 'part_title';
        $fallbackUsed = false;

        if ($productName === '') {
            $fallbackUsed = true;
            $pieces = array_filter([data_get($part->vehicle_snapshot, 'make'), data_get($part->vehicle_snapshot, 'model'), $part->category?->name, $mainPartCode], fn ($value) => filled($value));
            $productName = trim(implode(' ', array_map(fn ($value) => trim((string) $value), $pieces)));
            $source = $productName !== '' && $productName !== $mainPartCode ? 'vehicle_category_code_fallback' : 'main_part_code_final_fallback';
        }

        if ($productName === '') {
            $productName = $mainPartCode;
            $source = 'main_part_code_final_fallback';
        }

        return ['product_name' => $productName, 'product_name_source' => $source, 'product_name_length' => mb_strlen($productName), 'part_title' => $partTitle, 'main_part_code' => $mainPartCode, 'product_name_fallback_used' => $fallbackUsed];
    }

    private function descriptionPayload(Part $part, array $payload, array $offerImages): ?array
    {
        $description = $payload['description'] ?? null;
        if (is_array($description) && $this->hasNonEmptyDescriptionSection($description)) return $description;

        $built = app(\App\Services\Marketplace\AllegroDescriptionBuilder::class)->build($part, $offerImages);
        $builtDescription = $built['description'] ?? null;

        return is_array($builtDescription) && $this->hasNonEmptyDescriptionSection($builtDescription) ? $builtDescription : null;
    }

    private function hasNonEmptyDescriptionSection(array $description): bool
    {
        foreach ((array) ($description['sections'] ?? []) as $section) {
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (! is_array($item)) continue;
                $type = strtoupper((string) ($item['type'] ?? ''));
                if ($type === 'TEXT' && trim(strip_tags((string) ($item['content'] ?? ''))) !== '') return true;
                if ($type === 'IMAGE' && filled($item['url'] ?? null)) return true;
            }
        }

        return false;
    }


    /** @return array<int, array<string, mixed>> */
    private function offerParametersPayload(array $payload, ?array $productSet): array
    {
        $offerParameters = $payload['parameters'] ?? $payload['allegro_parameters']['payload_parameters'] ?? $payload['allegro_offer_parameters'] ?? $payload['allegro_parameters']['offer_parameters'] ?? [];
        $productParameterIds = array_flip($this->parameterIds(data_get($productSet, '0.product.parameters', [])));

        return array_values(array_filter((array) $offerParameters, fn (mixed $parameter): bool => is_array($parameter) && filled($parameter['id'] ?? null) && ! isset($productParameterIds[(string) $parameter['id']])));
    }

    private function productSetPayload(array $settings, array $payload, string $offerName, ?string $mainImageUrl = null): ?array
    {
        $productSet = is_array($payload['productSet'] ?? null) ? $payload['productSet'] : (is_array($settings['productSet'] ?? null) ? $settings['productSet'] : []);
        if ($productSet === []) $productSet = [['product' => ['parameters' => $payload['allegro_product_parameters'] ?? $payload['allegro_parameters']['product_parameters'] ?? []]]];
        if (! is_array($productSet[0]['product'] ?? null)) $productSet[0]['product'] = [];
        if (filled($offerName)) $productSet[0]['product']['name'] = trim($offerName);
        if (filled($mainImageUrl)) $productSet[0]['product']['images'] = [trim($mainImageUrl)];
        $responsibleProducer = $this->responsibleProducer($settings, $payload);
        $safetyInformation = $this->safetyInformation($settings, $payload);
        if ($responsibleProducer !== null) $productSet[0]['responsibleProducer'] = $responsibleProducer;
        if ($safetyInformation !== null) $productSet[0]['safetyInformation'] = $safetyInformation;
        return $productSet === [] ? null : $productSet;
    }

    private function responsibleProducer(array $settings, array $payload): ?array
    {
        $value = $settings['responsibleProducer'] ?? data_get($settings, 'gpsr.responsibleProducer') ?? data_get($settings, 'productSet.0.responsibleProducer') ?? data_get($payload, 'productSet.0.responsibleProducer');
        if (! is_array($value)) return null;
        $type = strtoupper((string) ($value['type'] ?? ''));
        if ($type === 'ID' && filled($value['id'] ?? null)) return ['type' => 'ID', 'id' => (string) $value['id']];
        if ($type === 'NAME' && filled($value['name'] ?? null)) return ['type' => 'NAME', 'name' => trim((string) $value['name'])];
        return null;
    }

    private function safetyInformation(array $settings, array $payload): ?array
    {
        $value = $settings['safetyInformation'] ?? data_get($settings, 'gpsr.safetyInformation') ?? data_get($settings, 'productSet.0.safetyInformation') ?? data_get($payload, 'productSet.0.safetyInformation');
        if (is_array($value) && strtoupper((string) ($value['type'] ?? '')) === 'TEXT' && filled($value['description'] ?? null)) return ['type' => 'TEXT', 'description' => trim(strip_tags((string) $value['description']))];
        if (is_string($value) && trim(strip_tags($value)) !== '') return ['type' => 'TEXT', 'description' => trim(strip_tags($value))];
        return ['type' => 'TEXT', 'description' => self::DEFAULT_SAFETY_INFORMATION];
    }

    private function deliverySettings(array $settings, array $salesSettings): ?array
    {
        $delivery = is_array($settings['delivery'] ?? null) ? $settings['delivery'] : [];
        $id = $salesSettings['shippingRates']['id'] ?? null;
        if (blank($id)) return $delivery ?: null;
        $delivery['shippingRates'] = ['id' => (string) $id];
        return $delivery;
    }

    private function afterSalesSettings(array $settings, array $salesSettings): ?array
    {
        $afterSales = is_array($settings['afterSalesServices'] ?? null) ? $settings['afterSalesServices'] : [];
        foreach (['returnPolicy', 'impliedWarranty', 'warranty'] as $key) {
            $id = $salesSettings[$key]['id'] ?? null;
            if (filled($id)) $afterSales[$key] = ['id' => (string) $id];
        }
        return $afterSales ?: null;
    }

    private function salesSettingsSummary(array $salesSettings): array
    {
        return collect($salesSettings)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row) => [
                'searched_name' => $row['searched_name'] ?? 'GPSWISS',
                'id' => $row['id'] ?? null,
                'found' => (bool) ($row['found'] ?? filled($row['id'] ?? null)),
                'status' => $row['status'] ?? (filled($row['id'] ?? null) ? 'mapped' : null),
                'reason' => $row['reason'] ?? null,
            ])->all();
    }

    /** @return array<int, string> */
    private function paymentsPayload(mixed $settingsPayments, mixed $payloadPayments): ?array
    {
        $payments = is_array($settingsPayments) ? $settingsPayments : [];
        if (is_array($payloadPayments) && filled($payloadPayments['invoice'] ?? null)) {
            $payments['invoice'] = (string) $payloadPayments['invoice'];
        }

        return $payments === [] ? null : $payments;
    }

    private function normalizeImageUrls(mixed $images): array
    {
        return array_values(array_filter(array_map(function (mixed $image): ?string {
            $url = null;
            if (is_string($image)) $url = $image;
            if (is_array($image)) {
                if (is_string($image['url'] ?? null)) $url = $image['url'];
                elseif (is_string($image['selected_image_url'] ?? null)) $url = $image['selected_image_url'];
            }
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
            'payload_parameters_ids' => $this->parameterIds(is_array($body) ? ($body['parameters'] ?? []) : ($payload['parameters'] ?? $payload['allegro_parameters']['payload_parameters'] ?? $payload['allegro_offer_parameters'] ?? $payload['allegro_parameters']['offer_parameters'] ?? [])),
            'productSet_0_product_parameters_ids' => $this->parameterIds(is_array($body) ? data_get($body, 'productSet.0.product.parameters', []) : data_get($payload, 'productSet.0.product.parameters', $payload['allegro_product_parameters'] ?? $payload['allegro_parameters']['product_parameters'] ?? [])),
            'offer_parameter_ids' => $this->parameterIds(is_array($body) ? ($body['parameters'] ?? []) : ($payload['parameters'] ?? $payload['allegro_parameters']['payload_parameters'] ?? $payload['allegro_offer_parameters'] ?? $payload['allegro_parameters']['offer_parameters'] ?? [])),
            'product_parameter_ids' => $this->parameterIds(is_array($body) ? data_get($body, 'productSet.0.product.parameters', []) : data_get($payload, 'productSet.0.product.parameters', $payload['allegro_product_parameters'] ?? $payload['allegro_parameters']['product_parameters'] ?? [])),
            'duplicated_parameter_ids' => $this->duplicatedParameterIds($payload, $body),
            'removed_from_offer_parameters_due_to_product_scope' => $this->removedFromOfferParametersDueToProductScope($payload, $body),
            'productSet_0_product_images_count' => count(data_get($body ?? $payload, 'productSet.0.product.images', [])),
            'productSet_0_product_main_image_present' => filled(data_get($body ?? $payload, 'productSet.0.product.images.0')),
            'description_sections_count' => count((array) data_get($body ?? $payload, 'description.sections', [])),
            'description_has_non_empty_content' => $this->hasNonEmptyDescriptionSection((array) data_get($body ?? $payload, 'description', [])),
        ];
    }

    /** @return array<int, string> */
    private function parameterIds(mixed $parameters): array
    {
        return array_values(array_filter(array_map(fn (mixed $parameter): ?string => is_array($parameter) && filled($parameter['id'] ?? null) ? (string) $parameter['id'] : null, (array) $parameters)));
    }


    /** @return array<int, string> */
    private function duplicatedParameterIds(array $payload, ?array $body): array
    {
        $offerIds = $this->parameterIds(is_array($body) ? ($body['parameters'] ?? []) : []);
        $productIds = $this->parameterIds(is_array($body) ? data_get($body, 'productSet.0.product.parameters', []) : []);

        return array_values(array_intersect($offerIds, $productIds));
    }

    /** @return array<int, string> */
    private function removedFromOfferParametersDueToProductScope(array $payload, ?array $body): array
    {
        if (! is_array($body)) return [];
        $rawOfferIds = $this->parameterIds($payload['parameters'] ?? $payload['allegro_parameters']['payload_parameters'] ?? $payload['allegro_offer_parameters'] ?? $payload['allegro_parameters']['offer_parameters'] ?? []);
        $bodyOfferIds = $this->parameterIds($body['parameters'] ?? []);
        $productIds = $this->parameterIds(data_get($body, 'productSet.0.product.parameters', []));

        return array_values(array_intersect(array_diff($rawOfferIds, $bodyOfferIds), $productIds));
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

<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\AllegroSalesSettingsResolver;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\AllegroDescriptionBuilder;
use App\Services\Marketplace\AllegroGpSwissDescriptionTemplate;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use App\Services\Marketplace\MarketplacePublishGate;
use App\Services\Marketplace\AllegroCategoryConsistencyGuard;

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
        $signature = $this->allegroSignature($part);
        $salesSettings = $this->allegroSalesSettingsResolver->resolve($account, $part->allegro_shipping_rate_name ?? null);
        if (($salesSettings['blockers'] ?? []) !== []) return ['ok' => false, 'status' => 'blocked_sales_settings', 'errors' => $salesSettings['blockers'], 'request_summary' => ['allegro_sales_settings' => $salesSettings], 'write' => false];
        $delivery = $this->deliverySettings($settings, $salesSettings);
        $afterSales = $this->afterSalesSettings($settings, $salesSettings);
        $client = new AllegroApiClient('allegro_main', $account);
        $offerImages = $this->normalizeImageUrls($payload['image_urls'] ?? $payload['images'] ?? []);
        $builtDescription = $this->descriptionPayload($part, $offerImages);
        $description = $builtDescription['description'];
        $productNameDiagnostics = $this->productNameDiagnostics($part, $payload);
        if (($productNameDiagnostics['product_name_length'] ?? 0) < 12) return ['ok' => false, 'status' => 'blocked_product_name_too_short', 'errors' => ['allegro_product_name_too_short'], 'request_summary' => $productNameDiagnostics, 'write' => false];
        $productSet = $this->productSetPayload($settings, $payload, $productNameDiagnostics['product_name'], $offerImages[0] ?? null);
        $offerParameters = $this->offerParametersPayload($payload, $productSet);
        $taxSettings = $this->validatedTaxSettings($client, (string) ($payload['category_id'] ?? ''));
        if (($taxSettings['blockers'] ?? []) !== []) return ['ok' => false, 'status' => 'blocked_tax_settings', 'errors' => $taxSettings['blockers'], 'warnings' => $taxSettings['warnings'] ?? [], 'request_summary' => $this->requestSummary($payload, null, $part) + $productNameDiagnostics + $signature + ['allegro_sales_settings' => $this->salesSettingsSummary($salesSettings), 'allegro_tax_settings' => $taxSettings], 'write' => false];
        $body = array_filter(['name' => (string) ($payload['title'] ?? $part->name), 'category' => ['id' => (string) ($payload['category_id'] ?? '')], 'productSet' => $productSet, 'parameters' => $offerParameters, 'images' => $offerImages, 'description' => $description, 'sellingMode' => $settings['sellingMode'] ?? ['format' => 'BUY_NOW', 'price' => ['amount' => (string) ($payload['price_pln'] ?? $readiness['marketplace_price']), 'currency' => 'PLN']], 'stock' => ['available' => (int) ($payload['quantity'] ?? $part->quantity ?? 1), 'unit' => 'UNIT'], 'publication' => ['status' => 'ACTIVE'], 'delivery' => $delivery, 'payments' => $this->paymentsPayload($settings['payments'] ?? null, $payload['payments'] ?? null), 'taxSettings' => $taxSettings['payload'] ?? null, 'afterSalesServices' => $afterSales, 'location' => $settings['location'] ?? null, 'external' => filled($signature['allegro_signature_value']) ? ['id' => $signature['allegro_signature_value']] : null], fn ($v) => $v !== null && $v !== []);
        $categoryConsistency = app(AllegroCategoryConsistencyGuard::class)->diagnose($part, $payload, $body);
        if (app(AllegroCategoryConsistencyGuard::class)->hasBlockingMismatch($categoryConsistency)) return ['ok' => false, 'status' => 'blocked_allegro_catalog_product_category_mismatch', 'action' => 'createProductOffer', 'error' => AllegroCategoryConsistencyGuard::MISMATCH_MARKER, 'ui_error' => $categoryConsistency['blocker_message'] ?? AllegroCategoryConsistencyGuard::MISMATCH_MARKER, 'request_summary' => $this->requestSummary($payload, $body, $part) + $productNameDiagnostics + $signature + ['allegro_sales_settings' => $this->salesSettingsSummary($salesSettings), 'allegro_tax_settings' => $taxSettings, 'category_consistency' => $categoryConsistency], 'response_summary' => ['blocked_before_allegro_api' => true], 'write' => false];
        $descriptionGuard = $this->assertGpSwissDescriptionTemplate($body, $builtDescription['diagnostics']);
        if (! $descriptionGuard['ok']) return ['ok' => false, 'status' => 'blocked', 'action' => 'createProductOffer', 'error' => 'allegro_description_template_not_applied', 'ui_error' => 'allegro_description_template_not_applied', 'request_summary' => $this->requestSummary($payload, $body, $part) + $productNameDiagnostics + $signature + ['allegro_sales_settings' => $this->salesSettingsSummary($salesSettings), 'description_guard' => $descriptionGuard], 'write' => false];
        $result = $client->createProductOffer($body);
        $offerId = filled($result['offer_id'] ?? null) ? (string) $result['offer_id'] : null;
        $offerUrl = $offerId ? 'https://allegro.pl/oferta/'.$offerId : null;
        $async = (int) ($result['http_status'] ?? 0) === 202;

        return ['ok' => $result['ok'] ?? false, 'action' => 'createProductOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $offerId, 'external_offer_id' => $offerId, 'external_listing_id' => $offerId, 'url' => $offerUrl, 'listing_status' => $async ? 'publication_pending' : 'published', 'request_id' => $result['request_id'] ?? null, 'operation_location' => $result['operation_location'] ?? null, 'async' => $async, 'log_context' => ['channel' => $this->channel(), 'allegro_offer_id' => $offerId, 'saved_url' => $offerUrl, 'operation_location' => $result['operation_location'] ?? null, 'async' => $async], 'request_summary' => $this->requestSummary($payload, $body, $part) + $productNameDiagnostics + $signature + ['allegro_sales_settings' => $this->salesSettingsSummary($salesSettings), 'allegro_tax_settings' => $taxSettings], 'response_summary' => $this->responseSummary($result), 'json' => $result['json'] ?? [], 'error' => 'Allegro product-offers publish failed.', 'ui_error' => 'Uzupełnij ustawienia sprzedaży Allegro GPSWISS. Szczegóły są w Logach.'];
    }

    /** @return array<string, mixed> */
    private function allegroSignature(Part $part): array
    {
        $part->loadMissing('storageLocation');

        $storageLocation = trim((string) ($part->storageLocation?->name ?? ''));
        $partCodes = array_values(array_filter([
            trim((string) ($part->part_number ?? '')),
            trim((string) ($part->oem_number ?? '')),
            trim((string) ($part->manufacturer_code ?? '')),
        ], fn (string $value): bool => $value !== ''));

        return [
            'allegro_signature_field' => 'external.id',
            'allegro_signature_value' => $storageLocation !== '' ? $storageLocation : null,
            'allegro_signature_source' => $storageLocation !== '' ? 'part.storageLocation.name' : 'none_missing_storage_location',
            'storage_location' => $storageLocation !== '' ? $storageLocation : null,
            'part_number' => $part->part_number,
            'oe_number' => $part->oem_number,
            'manufacturer_code' => $part->manufacturer_code,
            'signature_uses_part_code' => $storageLocation !== '' && in_array($storageLocation, $partCodes, true),
        ];
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

    /** @return array{description: ?array<string, mixed>, diagnostics: array<string, mixed>, blockers: array<int, string>} */
    private function descriptionPayload(Part $part, array $offerImages): array
    {
        $built = app(AllegroDescriptionBuilder::class)->build($part, $offerImages);
        $builtDescription = $built['description'] ?? null;

        return [
            'description' => is_array($builtDescription) && $this->hasNonEmptyDescriptionSection($builtDescription) ? $builtDescription : null,
            'diagnostics' => (array) ($built['diagnostics'] ?? []),
            'blockers' => (array) ($built['blockers'] ?? []),
        ];
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $builderDiagnostics */
    private function assertGpSwissDescriptionTemplate(array $body, array $builderDiagnostics = []): array
    {
        $description = (array) data_get($body, 'description', []);
        $text = '';
        $hasTextImage5050 = false;
        foreach ((array) ($description['sections'] ?? []) as $section) {
            $items = array_values((array) ($section['items'] ?? []));
            if (count($items) >= 2 && strtoupper((string) ($items[0]['type'] ?? '')) === 'TEXT' && strtoupper((string) ($items[1]['type'] ?? '')) === 'IMAGE') {
                $hasTextImage5050 = true;
            }
            foreach ($items as $item) {
                if (is_array($item) && strtoupper((string) ($item['type'] ?? '')) === 'TEXT') $text .= ' '.strip_tags((string) ($item['content'] ?? ''));
            }
        }

        $intro = str_contains($text, 'Witam oferta dotyczy');
        $footer = str_contains($text, 'CZĘŚĆ SPRAWNA. STAN WIDOCZNY NA ZDJĘCIACH');
        $template = $hasTextImage5050 ? AllegroGpSwissDescriptionTemplate::TEMPLATE : null;

        return array_merge($builderDiagnostics, [
            'ok' => $intro && $footer && $template === AllegroGpSwissDescriptionTemplate::TEMPLATE,
            'blocker' => $intro && $footer && $template === AllegroGpSwissDescriptionTemplate::TEMPLATE ? null : 'allegro_description_template_not_applied',
            'description_source' => AllegroGpSwissDescriptionTemplate::SOURCE,
            'description_template' => $template,
            'description_builder_class' => AllegroDescriptionBuilder::class,
            'description_contains_gp_swiss_intro' => $intro,
            'description_contains_gp_swiss_footer' => $footer,
            'description_contains_vehicle_fields' => str_contains($text, 'Marka:') && str_contains($text, 'Model:'),
            'description_publish_blocked_if_template_missing' => true,
        ]);
    }

    private function sanitizedPartDescription(Part $part): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($part->description ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
    }

    private function descriptionDiagnostics(Part $part, ?array $body = null): array
    {
        $sanitized = $this->sanitizedPartDescription($part);
        $guard = is_array($body) ? $this->assertGpSwissDescriptionTemplate($body) : [];

        return array_merge([
            'description_present' => $this->hasNonEmptyDescriptionSection((array) data_get($body, 'description', [])),
            'description_source' => AllegroGpSwissDescriptionTemplate::SOURCE,
            'description_template' => AllegroGpSwissDescriptionTemplate::TEMPLATE,
            'description_builder_class' => AllegroDescriptionBuilder::class,
            'description_sanitized_length' => mb_strlen($sanitized),
        ], $guard);
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

    /** @return array{payload: array<string, mixed>|null, warnings: array<int, string>, blockers: array<int, string>, lookup: array<string, mixed>} */
    private function validatedTaxSettings(AllegroApiClient $client, string $categoryId): array
    {
        $desired = [
            ['countryCode' => 'PL', 'rate' => '23.00'],
            ['countryCode' => 'CZ', 'rate' => '21.00'],
            ['countryCode' => 'SK', 'rate' => '23.00'],
            ['countryCode' => 'HU', 'rate' => '27.00'],
            ['countryCode' => 'LT', 'rate' => '21.00'],
        ];
        $lookup = $categoryId !== '' ? $client->taxSettings($categoryId) : ['ok' => false, 'http_status' => null, 'json' => [], 'error' => 'Missing Allegro category id for tax settings lookup.'];
        $warnings = [];
        $blockers = [];
        if (($lookup['ok'] ?? false) !== true) {
            $blockers[] = 'allegro_tax_settings_lookup_failed';
            return ['payload' => null, 'warnings' => $warnings, 'blockers' => $blockers, 'lookup' => $this->taxSettingsLookupSummary($lookup)];
        }

        $json = is_array($lookup['json'] ?? null) ? $lookup['json'] : [];
        $allowed = $this->allowedTaxSettings($json);
        $subject = $this->matchedTaxSubject($allowed['subjects'], 'GOODS');
        if ($subject === null) $blockers[] = 'allegro_tax_settings_subject_goods_not_supported';
        $matches = [];
        $matchedRates = [];
        foreach ($desired as $rate) {
            $match = $this->matchedTaxRate($allowed['rates'], $rate['countryCode'], $rate['rate']);
            $matches[] = [
                'countryCode' => $rate['countryCode'],
                'requested_rate' => $rate['rate'],
                'requested_rate_normalized' => $this->normalizeTaxRate($rate['rate']),
                'matched' => $match !== null,
                'matched_allowed_value' => $match['payload_value'] ?? null,
                'matched_allowed_rate' => $match['sanitized'] ?? null,
                'reason' => $match === null ? 'requested_vat_rate_not_present_in_allegro_allowed_values_for_country' : null,
            ];
            if ($match === null) {
                $warnings[] = 'allegro_tax_rate_not_supported:'.$rate['countryCode'].':'.$rate['rate'];
                continue;
            }
            $rate['rate'] = $match['payload_value'];
            $matchedRates[] = $rate;
        }
        if (count($matchedRates ?? []) !== count($desired)) $blockers[] = 'allegro_tax_settings_rates_not_supported';

        return [
            'payload' => $blockers === [] ? ['subject' => $subject['payload_value'] ?? 'GOODS', 'rates' => $matchedRates] : null,
            'warnings' => $warnings,
            'blockers' => $blockers,
            'lookup' => $this->taxSettingsLookupSummary($lookup),
            'allowed' => $allowed,
            'desired' => ['subject' => 'GOODS', 'rates' => $desired],
            'matches' => $matches,
        ];
    }

    private function matchedTaxSubject(array $subjects, string $subject): ?array
    {
        if ($subjects === []) return ['payload_value' => $subject];
        foreach ($subjects as $row) {
            if (strtoupper((string) ($row['value'] ?? $row['id'] ?? $row['subject'] ?? $row['name'] ?? '')) === $subject) return $row + ['payload_value' => (string) ($row['value'] ?? $subject)];
        }
        return null;
    }

    private function matchedTaxRate(array $rates, string $countryCode, string $rate): ?array
    {
        if ($rates === []) return ['payload_value' => $rate, 'sanitized' => ['rate' => $rate]];
        $needle = $this->normalizeTaxRate($rate);
        foreach ($rates as $row) {
            if (strtoupper((string) ($row['countryCode'] ?? '')) !== strtoupper($countryCode)) continue;
            if (($row['normalized_rate'] ?? null) === $needle) return $row;
        }
        return null;
    }

    private function normalizeTaxRate(mixed $rate): ?string
    {
        $value = trim(str_replace(['%', ','], ['', '.'], (string) $rate));
        if ($value === '' || ! is_numeric($value)) return null;
        return number_format((float) $value, 2, '.', '');
    }

    private function allowedTaxSettings(array $json): array
    {
        $subjects = array_values(array_filter(array_map(fn ($row) => $this->sanitizeTaxAllowedRow($row), (array) ($json['subjects'] ?? $json['availableSubjects'] ?? data_get($json, 'taxSettings.subjects', [])))));
        $rates = [];
        foreach ((array) ($json['rates'] ?? $json['taxRates'] ?? data_get($json, 'taxSettings.rates', [])) as $row) {
            if (! is_array($row)) continue;
            $country = strtoupper((string) ($row['countryCode'] ?? $row['country'] ?? ''));
            $values = is_array($row['values'] ?? null) ? $row['values'] : [$row];
            foreach ($values as $valueRow) {
                if (! is_array($valueRow)) continue;
                $sanitized = $this->sanitizeTaxAllowedRow($valueRow + ['countryCode' => $country]);
                $payloadValue = $valueRow['value'] ?? $valueRow['rate'] ?? $valueRow['id'] ?? ($valueRow['label'] ?? null);
                $normalized = $this->normalizeTaxRate($payloadValue);
                if ($country === '' || $normalized === null) continue;
                $rates[] = ['countryCode' => $country, 'normalized_rate' => $normalized, 'payload_value' => (string) $payloadValue, 'sanitized' => $sanitized];
            }
        }
        $exemptions = array_values(array_filter(array_map(fn ($row) => $this->sanitizeTaxAllowedRow($row), (array) ($json['exemptions'] ?? data_get($json, 'taxSettings.exemptions', [])))));
        return ['subjects' => $subjects, 'rates' => $rates, 'exemptions' => $exemptions];
    }

    private function sanitizeTaxAllowedRow(mixed $row): ?array
    {
        if (! is_array($row)) return $row === null || $row === '' ? null : ['value' => (string) $row];
        return array_filter([
            'countryCode' => $row['countryCode'] ?? $row['country'] ?? null,
            'rate' => $row['rate'] ?? null,
            'value' => $row['value'] ?? null,
            'label' => $row['label'] ?? null,
            'id' => $row['id'] ?? null,
            'subject' => $row['subject'] ?? null,
            'name' => $row['name'] ?? null,
            'exemptionRequired' => $row['exemptionRequired'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function taxSettingsLookupSummary(array $lookup): array
    {
        return [
            'ok' => (bool) ($lookup['ok'] ?? false),
            'http_status' => $lookup['http_status'] ?? null,
            'request_id' => $lookup['request_id'] ?? null,
            'top_level_keys' => array_slice(array_keys(is_array($lookup['json'] ?? null) ? $lookup['json'] : []), 0, 20),
        ];
    }

    /** @return array<int, string> */
    private function paymentsPayload(mixed $settingsPayments, mixed $payloadPayments): ?array
    {
        $payments = is_array($settingsPayments) ? $settingsPayments : [];
        if (is_array($payloadPayments) && filled($payloadPayments['invoice'] ?? null)) {
            $payments['invoice'] = (string) $payloadPayments['invoice'];
        }

        $payments['invoice'] = 'VAT';

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

    protected function requestSummary(array $payload, ?array $body = null, ?Part $part = null): array
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
            'category_consistency' => app(AllegroCategoryConsistencyGuard::class)->diagnose($part, $payload, $body),
        ] + ($part ? $this->descriptionDiagnostics($part, $body) : []);
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

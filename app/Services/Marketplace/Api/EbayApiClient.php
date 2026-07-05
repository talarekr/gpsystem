<?php

namespace App\Services\Marketplace\Api;

use App\Services\Marketplace\OAuthTokenManager;
use App\Support\Marketplace\EbayOAuthConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EbayApiClient extends AbstractMarketplaceApiClient
{
    protected function requiredCredentialKeys(): array { return ['access_token']; }
    protected function optionalCredentialKeys(): array { return ['client_id', 'client_secret', 'refresh_token', 'dev_id', 'ru_name']; }
    protected function endpointPath(): string { return '/sell/inventory/v1/inventory_item'; }

    protected function requestSample(int $limit): array
    {
        $response = $this->getWithAuthRetry($this->endpointUsed($limit), [], [], 15);
        $json = $response->json();
        $error = in_array($response->status(), [401, 403], true) ? 'OAuth token expired, unauthorized, forbidden, or missing Inventory API scope.' : null;
        return ['http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'api_ok' => $response->successful(), 'error' => $error];
    }


    public function createShippingFulfillment(\App\Models\Order $order): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $orderId = (string) $order->marketplace_order_id;
        $shipment = $order->shipments()->latest('id')->first();
        $lineItems = $order->items()->get()->map(fn ($item): array => array_filter(['lineItemId' => $item->marketplace_item_id ?: data_get($item->raw_payload, 'lineItemId'), 'quantity' => (int) $item->quantity], fn ($value) => $value !== null && $value !== ''))->values()->all();
        $payload = array_filter(['lineItems' => $lineItems, 'shippedDate' => now()->toISOString(), 'shippingCarrierCode' => $shipment?->carrier, 'trackingNumber' => $shipment?->tracking_number], fn ($value) => $value !== null && $value !== '' && $value !== []);
        $endpoint = $base.'/sell/fulfillment/v1/order/'.rawurlencode($orderId).'/shipping_fulfillment';
        $missingReasons = array_values(array_filter([
            $orderId === '' ? 'missing_marketplace_order_id' : null,
            $lineItems === [] ? 'missing_line_items' : null,
            blank($shipment?->carrier) ? 'missing_carrier' : null,
            blank($shipment?->tracking_number) ? 'missing_tracking_number' : null,
        ]));
        $requestSummary = [
            'method' => 'POST',
            'endpoint' => 'POST /sell/fulfillment/v1/order/{orderId}/shipping_fulfillment',
            'order_id' => $orderId,
            'payload' => $payload,
            'account_code' => $this->account?->code,
            'account_marketplace' => $this->account?->marketplace,
            'channel' => $this->channel,
        ];
        if ($missingReasons !== []) return ['ok' => false, 'http_status' => null, 'action' => 'ebay_create_shipping_fulfillment', 'message' => $missingReasons[0], 'request_summary' => $requestSummary, 'response_summary' => ['blocker' => $missingReasons[0], 'missing_reasons' => $missingReasons, 'missing' => ['order_id' => $orderId === '', 'line_items' => $lineItems === [], 'carrier' => blank($shipment?->carrier), 'tracking_number' => blank($shipment?->tracking_number)]]];
        try {
            $response = $this->postWithAuthRetry($endpoint, $payload, [], 20); $json = $response->json(); $body = is_array($json) ? $json : [];
            return ['ok' => $response->successful(), 'http_status' => $response->status(), 'action' => 'ebay_create_shipping_fulfillment', 'message' => $response->successful() ? 'eBay shipping fulfillment created.' : 'eBay shipping fulfillment failed.', 'request_summary' => $requestSummary, 'response_summary' => ['fulfillment_id' => $body['fulfillmentId'] ?? null, 'errors' => $body['errors'] ?? null, 'warnings' => $body['warnings'] ?? null, 'top_level_keys' => array_slice(array_keys($body), 0, 20)]];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'http_status' => null, 'action' => 'ebay_create_shipping_fulfillment', 'message' => 'eBay shipping fulfillment failed.', 'request_summary' => $requestSummary, 'response_summary' => ['error_class' => $exception::class, 'error_message_safe' => $exception->getMessage()]];
        }
    }

    public function endOffer(string $offerIdOrSku, ?string $sku = null): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $marketplaceId = $this->marketplaceId();
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId];
        $endpoint = $base.'/sell/inventory/v1/offer/'.rawurlencode($offerIdOrSku).'/withdraw';
        $response = $this->postWithAuthRetry($endpoint, [], $headers, 20);
        $json = $response->json();
        $body = is_array($json) ? $json : [];

        return [
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'message' => $response->successful() ? 'eBay offer withdrawn.' : 'eBay withdraw offer failed.',
            'request_summary' => ['endpoint' => 'POST /sell/inventory/v1/offer/{offerId}/withdraw', 'offer_id_or_sku' => $offerIdOrSku, 'sku' => $sku, 'marketplace_id' => $marketplaceId],
            'response_summary' => ['top_level_keys' => array_slice(array_keys($body), 0, 20), 'warnings_count' => is_countable($body['warnings'] ?? null) ? count($body['warnings']) : 0],
        ];
    }

    public function setInventoryQuantity(string $sku, int $quantity, ?string $offerId = null): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $marketplaceId = $this->marketplaceId();
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId];
        $endpoint = $base.'/sell/inventory/v1/bulk_update_price_quantity';
        $payload = ['requests' => [[
            'sku' => $sku,
            'shipToLocationAvailability' => ['quantity' => max(0, $quantity)],
        ]]];
        $response = $this->postWithAuthRetry($endpoint, $payload, $headers, 20);
        $json = $response->json();
        $body = is_array($json) ? $json : [];

        return [
            'ok' => $response->successful(),
            'action' => 'ebay_set_inventory_quantity',
            'http_status' => $response->status(),
            'message' => $response->successful() ? 'eBay inventory quantity updated.' : 'eBay inventory quantity update failed.',
            'request_summary' => ['endpoint' => 'POST /sell/inventory/v1/bulk_update_price_quantity', 'sku' => $sku, 'offer_id' => $offerId, 'quantity' => max(0, $quantity), 'marketplace_id' => $marketplaceId, 'out_of_stock_control_required' => true, 'no_withdraw_or_relist' => true],
            'response_summary' => ['top_level_keys' => array_slice(array_keys($body), 0, 20), 'responses_count' => is_countable($body['responses'] ?? null) ? count($body['responses']) : null, 'errors_count' => is_countable($body['errors'] ?? null) ? count($body['errors']) : 0, 'warnings_count' => is_countable($body['warnings'] ?? null) ? count($body['warnings']) : 0],
        ];
    }

    public function readOnlyDiagnostics(): array
    {
        $token = $this->accessToken();
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => (string) (($this->account?->api_settings ?? [])['marketplace_id'] ?? 'EBAY_DE')];
        $calls = [
            'account_fulfillment_policies' => Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(15)->get($base.'/sell/account/v1/fulfillment_policy', ['marketplace_id' => $headers['X-EBAY-C-MARKETPLACE-ID']]),
            'account_payment_policies' => Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(15)->get($base.'/sell/account/v1/payment_policy', ['marketplace_id' => $headers['X-EBAY-C-MARKETPLACE-ID']]),
            'account_return_policies' => Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(15)->get($base.'/sell/account/v1/return_policy', ['marketplace_id' => $headers['X-EBAY-C-MARKETPLACE-ID']]),
            'inventory_items' => Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(15)->get($base.'/sell/inventory/v1/inventory_item', ['limit' => 1]),
            'fulfillment_orders' => Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(15)->get($base.'/sell/fulfillment/v1/order', ['limit' => 1, 'filter' => 'creationdate:['.now()->subDays(30)->toISOString().'..'.now()->toISOString().']']),
        ];

        $results = [];
        foreach ($calls as $name => $response) {
            $json = $response->json();
            $results[$name] = ['http_status' => $response->status(), 'ok' => $response->successful(), 'top_level_keys' => is_array($json) ? array_slice(array_keys($json), 0, 20) : []];
        }

        return ['ok' => collect($results)->every(fn ($row) => (bool) $row['ok']), 'channel' => $this->channel, 'read_only' => true, 'results' => $results];
    }

    public function automotivePartsCompatibilityPolicies(string $categoryId): array
    {
        $readiness = $this->getAccountReadiness();
        $marketplaceId = $this->marketplaceId();
        $base = ['ok' => false, 'channel' => $this->channel, 'marketplace_id' => $marketplaceId, 'category_id' => $categoryId, 'supports_compatibility' => false, 'policy' => null, 'compatibility_classification' => null, 'max_number_of_compatible_vehicles' => null, 'required_properties' => [], 'blockers' => $readiness['blockers'] ?? [], 'warnings' => [], 'read_only' => true];
        if ($base['blockers'] !== []) return $base;
        $json = $this->cachedEbayGet('metadata.compatibility_policies.'.$marketplaceId.'.'.$categoryId, '/sell/metadata/v1/marketplace/'.$marketplaceId.'/get_automotive_parts_compatibility_policies', ['filter' => 'categoryIds:{'.$categoryId.'}']);
        if (! ($json['ok'] ?? false)) return array_merge($base, ['blockers' => ['Could not read eBay automotive parts compatibility policies (HTTP '.($json['http_status'] ?? 'n/a').').']]);
        $payload = is_array($json['json'] ?? null) ? $json['json'] : [];
        $policies = array_values(array_filter($payload['automotivePartsCompatibilityPolicies'] ?? [], fn ($p) => is_array($p) && (string)($p['categoryId'] ?? '') === $categoryId));
        $policy = $policies[0] ?? null;
        $base['warnings'] = array_values(array_filter(array_map(fn ($w) => is_array($w) ? ($w['message'] ?? $w['errorId'] ?? null) : null, $payload['warnings'] ?? [])));
        $base['policy'] = $policy;
        $base['supports_compatibility'] = $policy !== null;
        $base['compatibility_classification'] = $policy['compatibilityClassification'] ?? $policy['compatibilityType'] ?? null;
        $base['max_number_of_compatible_vehicles'] = $policy['maxNumberOfCompatibleVehicles'] ?? $policy['maxCompatibleVehicles'] ?? null;
        $base['required_properties'] = $policy['requiredProperties'] ?? $policy['requiredCompatibilityProperties'] ?? [];
        $base['ok'] = true;
        if (! $base['supports_compatibility']) $base['blockers'][] = 'Category not returned by eBay as supporting automotive parts compatibility.';
        return $base;
    }

    public function compatibilityProperties(string $categoryId): array
    {
        $readiness = $this->getAccountReadiness();
        $marketplaceId = $this->marketplaceId();
        $treeId = $this->categoryTreeId();
        $base = ['ok' => false, 'channel' => $this->channel, 'marketplace_id' => $marketplaceId, 'category_id' => $categoryId, 'properties_count' => 0, 'properties' => [], 'blockers' => $readiness['blockers'] ?? [], 'warnings' => [], 'read_only' => true];
        if ($base['blockers'] !== []) return $base;
        $json = $this->cachedEbayGet('taxonomy.compatibility_properties.'.$treeId.'.'.$categoryId, '/commerce/taxonomy/v1/category_tree/'.$treeId.'/get_compatibility_properties', ['category_id' => $categoryId]);
        if (! ($json['ok'] ?? false)) return array_merge($base, ['blockers' => ['Could not read eBay compatibility properties (HTTP '.($json['http_status'] ?? 'n/a').').']]);
        $payload = is_array($json['json'] ?? null) ? $json['json'] : [];
        $props = array_values(array_filter($payload['compatibilityProperties'] ?? [], 'is_array'));
        $base['properties'] = array_map(fn ($p) => ['name' => $p['name'] ?? $p['localizedName'] ?? null, 'localized_name' => $p['localizedName'] ?? $p['name'] ?? null, 'required' => (bool)($p['required'] ?? false), 'usage' => $p['usage'] ?? null, 'allowed_values_available' => (bool)($p['allowedValuesAvailable'] ?? true), 'raw' => $p], $props);
        $base['properties_count'] = count($base['properties']);
        $base['ok'] = true;
        return $base;
    }

    public function compatibilityPropertyValues(string $categoryId, string $property, array $filters = [], string $query = '', int $limit = 50, bool $includeAll = false): array
    {
        $readiness = $this->getAccountReadiness();
        $marketplaceId = $this->marketplaceId();
        $treeId = $this->categoryTreeId();
        $path = '/commerce/taxonomy/v1/category_tree/'.$treeId.'/get_compatibility_property_values';
        $query = trim($query);
        $limit = max(1, min($limit, $includeAll ? 1000 : 50));
        $apiFilters = array_diff_key($filters, array_flip(['q', 'limit', 'include_all']));
        $filterQuery = $this->compatibilityPropertyValueFilter($apiFilters);
        $params = array_filter([
            'category_id' => $categoryId,
            'compatibility_property' => $property,
            'filter' => $filterQuery,
        ], fn ($value) => $value !== null && $value !== '');
        $base = [
            'ok' => false,
            'channel' => $this->channel,
            'marketplace_id' => $marketplaceId,
            'category_tree_id' => $treeId,
            'category_id' => $categoryId,
            'property' => $property,
            'applied_filters' => $filterQuery,
            'values_count' => 0,
            'values_sample' => [],
            'query' => $query,
            'matched_values' => [],
            'matched_values_count' => 0,
            'has_more' => false,
            'limit' => $limit,
            'api_endpoint_family' => 'commerce/taxonomy/category_tree/get_compatibility_property_values',
            'api_request_path_safe' => $path,
            'api_query_safe' => $params,
            'blockers' => $readiness['blockers'] ?? [],
            'warnings' => ['Read-only diagnostics only: no eBay write API calls and no local product, listing, offer, or marketplace mutation.'],
            'read_only' => true,
        ];
        if ($base['blockers'] !== []) return $base;

        $json = $this->cachedEbayGet('taxonomy.compatibility_values.'.$treeId.'.'.$categoryId.'.'.md5(json_encode($params)), $path, $params);
        if (! ($json['ok'] ?? false)) {
            return array_merge($base, $this->safeEbayErrorDetails($json), ['blockers' => ['Could not read eBay compatibility property values (HTTP '.($json['http_status'] ?? 'n/a').'). See safe API error fields for details.']]);
        }
        $payload = is_array($json['json'] ?? null) ? $json['json'] : [];
        $values = array_values(array_filter($payload['compatibilityPropertyValues'] ?? $payload['values'] ?? [], 'is_array'));
        $valueLabels = array_map(fn ($v) => $this->compatibilityPropertyValueLabel($v), $values);
        $base['values_count'] = count($values);
        $base['values_sample'] = array_slice($valueLabels, 0, $limit);

        if ($query !== '') {
            $matched = array_values(array_filter($valueLabels, fn ($value) => stripos((string) $value, $query) !== false));
            $base['matched_values_count'] = count($matched);
            $base['matched_values'] = array_slice($matched, 0, $limit);
            $base['has_more'] = count($matched) > count($base['matched_values']);
        }

        $base['ok'] = true;
        return $base;
    }


    private function compatibilityPropertyValueLabel(array $value): string
    {
        $label = $value['value'] ?? $value['localizedValue'] ?? null;

        return is_scalar($label) ? (string) $label : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function cachedEbayGet(string $key, string $path, array $query): array
    {
        return Cache::remember('ebay_readonly:'.$key, now()->addHours(24), function () use ($path, $query) {
            $response = Http::withToken($this->accessToken())->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId()])->acceptJson()->timeout(20)->get(rtrim((string) $this->account?->api_base_url, '/').$path, $query);
            return ['ok' => $response->successful(), 'http_status' => $response->status(), 'json' => is_array($response->json()) ? $response->json() : [], 'path' => $path, 'query' => $query];
        });
    }

    private function compatibilityPropertyValueFilter(array $filters): ?string
    {
        if (isset($filters['filter']) && is_string($filters['filter']) && trim($filters['filter']) !== '') return trim($filters['filter']);

        $pairs = [];
        $genericFilters = is_array($filters['filters'] ?? null) ? $filters['filters'] : [];
        foreach ($genericFilters as $name => $value) {
            if (is_string($name) && ! blank($value)) $pairs[$name] = (string) $value;
        }

        foreach (['make' => 'Make', 'model' => 'Model', 'year' => 'Year', 'platform' => 'Platform', 'type' => 'Type', 'engine' => 'Engine', 'trim' => 'Trim'] as $suffix => $propertyName) {
            $value = $filters['filter_'.$suffix] ?? null;
            if (! blank($value)) $pairs[$propertyName] = (string) $value;
        }

        if ($pairs === []) return null;

        return collect($pairs)
            ->map(fn (string $value, string $name) => $name.':'.$value)
            ->implode(',');
    }

    private function safeEbayErrorDetails(array $response): array
    {
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $error = is_array($json['errors'][0] ?? null) ? $json['errors'][0] : [];

        return [
            'api_http_status' => $response['http_status'] ?? null,
            'ebay_error_id' => $error['errorId'] ?? null,
            'ebay_error_message' => $error['message'] ?? $json['message'] ?? null,
            'ebay_error_long_message' => $error['longMessage'] ?? null,
        ];
    }

    private function marketplaceId(): string { return (string) (($this->account?->api_settings ?? [])['marketplace_id'] ?? ($this->channel === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE')); }
    private function categoryTreeId(): string { $settings = is_array($this->account?->api_settings) ? $this->account->api_settings : []; $marketplaceId = $this->marketplaceId(); if ($marketplaceId === 'EBAY_DE') return '77'; if ($marketplaceId === 'EBAY_FR') return '71'; return (string) ($settings['category_tree_id'] ?? $settings['site_id'] ?? ($this->channel === 'ebay_fr' ? '71' : '77')); }


    public function businessPoliciesDiagnostics(): array
    {
        $readiness = $this->getAccountReadiness();
        $marketplaceId = (string) (($this->account?->api_settings ?? [])['marketplace_id'] ?? '');
        $base = [
            'ok' => false,
            'channel' => $this->channel,
            'marketplace_id' => $marketplaceId ?: null,
            'api_mode' => $this->account?->api_mode,
            'fulfillment_policies_count' => 0,
            'payment_policies_count' => 0,
            'return_policies_count' => 0,
            'fulfillment_policies' => [],
            'payment_policies' => [],
            'return_policies' => [],
            'blockers' => $readiness['blockers'] ?? [],
            'warnings' => $readiness['warnings'] ?? [],
            'read_only' => true,
        ];

        if ($base['blockers'] !== []) return $base;

        $token = $this->accessToken();
        $baseUrl = rtrim((string) $this->account?->api_base_url, '/');
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId ?: 'EBAY_DE'];
        $endpoints = [
            'fulfillment' => ['path' => '/sell/account/v1/fulfillment_policy', 'json_key' => 'fulfillmentPolicies'],
            'payment' => ['path' => '/sell/account/v1/payment_policy', 'json_key' => 'paymentPolicies'],
            'return' => ['path' => '/sell/account/v1/return_policy', 'json_key' => 'returnPolicies'],
        ];

        foreach ($endpoints as $type => $endpoint) {
            $response = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(15)->get($baseUrl.$endpoint['path'], ['marketplace_id' => $headers['X-EBAY-C-MARKETPLACE-ID']]);
            $json = $response->json();

            if (! $response->successful()) {
                $base['blockers'][] = sprintf('Could not read %s policies from eBay Account API (HTTP %s).', $type, $response->status());
                continue;
            }

            $policies = is_array($json) && is_array($json[$endpoint['json_key']] ?? null) ? $json[$endpoint['json_key']] : [];
            $base[$type.'_policies'] = array_values(array_map(fn (array $policy) => $this->formatBusinessPolicy($policy), array_filter($policies, 'is_array')));
            $base[$type.'_policies_count'] = count($base[$type.'_policies']);
        }

        $base['ok'] = $base['blockers'] === [];

        return $base;
    }

    public function getListingStatusByItemId(string $itemId, ?string $marketplaceId = null): array
    {
        $readiness = $this->getAccountReadiness();
        $marketplaceId = $marketplaceId ?: $this->marketplaceId();
        $base = [
            'ok' => false,
            'read_only' => true,
            'channel' => $this->channel,
            'item_id' => $itemId,
            'marketplace_id' => $marketplaceId,
            'api_listing_status' => 'unknown',
            'http_status' => null,
            'raw_api_status' => null,
            'listing_marketplace_id' => null,
            'item_web_url' => null,
            'end_date' => null,
            'blockers' => $readiness['blockers'] ?? [],
            'warnings' => ['Read-only eBay Browse API item lookup only; no write, publish, revise, relist, end, stock, price, or local mutation is performed.'],
        ];
        if ($base['blockers'] !== []) return $base;

        $response = Http::withToken($this->accessToken())
            ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId])
            ->acceptJson()
            ->timeout(15)
            ->get(rtrim((string) $this->account?->api_base_url, '/').'/buy/browse/v1/item/v1|'.$itemId.'|0');
        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        $availability = strtoupper((string) data_get($payload, 'estimatedAvailabilities.0.estimatedAvailabilityStatus', ''));
        $endDate = $payload['itemEndDate'] ?? null;
        $endDateIsPast = filled($endDate) && strtotime((string) $endDate) !== false && strtotime((string) $endDate) < now()->timestamp;
        $rawStatus = $availability ?: ($endDate ?? $payload['itemCreationDate'] ?? null);
        $status = $this->normalizeBrowseItemStatus($response->status(), $payload);

        return array_merge($base, [
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'api_listing_status' => $status,
            'raw_api_status' => $rawStatus,
            'listing_marketplace_id' => $payload['itemLocation']['country'] ?? null,
            'item_web_url' => $payload['itemWebUrl'] ?? null,
            'end_date' => $endDate,
            'end_date_is_past' => $endDateIsPast,
            'availability_status' => $availability ?: null,
            'title_present' => filled($payload['title'] ?? null),
            'safe_top_level_keys' => array_slice(array_keys($payload), 0, 20),
        ]);
    }

    public function getItemDescriptionByItemId(string $itemId): array
    {
        $readiness = $this->getAccountReadiness();
        $siteId = (string) (($this->account?->api_settings ?? [])['site_id'] ?? ($this->marketplaceId() === 'EBAY_FR' ? '71' : '77'));
        $base = [
            'ok' => false,
            'read_only' => true,
            'api_endpoint_family' => 'trading.get_item',
            'live_description_source' => 'not_available',
            'item_id' => $itemId,
            'trading_site_id' => $siteId,
            'trading_http_status' => null,
            'description_html' => null,
            'description_length' => 0,
            'blockers' => $readiness['blockers'] ?? [],
            'warnings' => ['Read-only eBay Trading API GetItem lookup only; no write, publish, revise, relist, end, stock, price, or local mutation is performed.'],
        ];
        if ($base['blockers'] !== []) return $base;

        $credentials = $this->credentials();
        $token = $this->accessToken();
        if (blank($token)) return array_merge($base, ['blockers' => ['Credential access_token is missing.']]);

        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
            .'<RequesterCredentials><eBayAuthToken>'.htmlspecialchars($token, ENT_XML1).'</eBayAuthToken></RequesterCredentials>'
            .'<ItemID>'.htmlspecialchars($itemId, ENT_XML1).'</ItemID>'
            .'<DetailLevel>ReturnAll</DetailLevel>'
            .'</GetItemRequest>';

        $response = Http::withHeaders(array_filter([
                'X-EBAY-API-CALL-NAME' => 'GetItem',
                'X-EBAY-API-SITEID' => $siteId,
                'X-EBAY-API-COMPATIBILITY-LEVEL' => (string) (($this->account?->api_settings ?? [])['trading_compatibility_level'] ?? '967'),
                'X-EBAY-API-IAF-TOKEN' => $token,
                'X-EBAY-API-APP-NAME' => $credentials['client_id'] ?? null,
                'Content-Type' => 'text/xml',
            ]))
            ->timeout(20)
            ->withBody($xml, 'text/xml')
            ->post($this->tradingApiUrl());

        $description = null;
        $ack = null;
        $errorMessage = null;
        if ($response->successful() && trim($response->body()) !== '') {
            libxml_use_internal_errors(true);
            $parsed = simplexml_load_string($response->body());
            if ($parsed instanceof \SimpleXMLElement) {
                $parsed->registerXPathNamespace('e', 'urn:ebay:apis:eBLBaseComponents');
                $ack = (string) ($parsed->Ack ?? '');
                $nodes = $parsed->xpath('//e:Item/e:Description') ?: $parsed->xpath('//Item/Description') ?: [];
                $description = isset($nodes[0]) ? (string) $nodes[0] : null;
                $errors = $parsed->xpath('//e:Errors/e:LongMessage') ?: $parsed->xpath('//Errors/LongMessage') ?: [];
                $errorMessage = isset($errors[0]) ? (string) $errors[0] : null;
            }
            libxml_clear_errors();
        }

        $hasDescription = is_string($description) && trim($description) !== '';

        return array_merge($base, [
            'ok' => $response->successful() && $hasDescription,
            'live_description_source' => $hasDescription ? 'ebay_trading_get_item' : 'not_available',
            'trading_http_status' => $response->status(),
            'trading_ack' => $ack,
            'description_html' => $description,
            'description_length' => is_string($description) ? mb_strlen($description) : 0,
            'blockers' => $hasDescription ? [] : array_values(array_filter(['cannot_fetch_live_description', $errorMessage])),
        ]);
    }



    /**
     * Read-only Inventory/Offer lookup used to diagnose inventory-based listings.
     * No publish, relist, end, stock, price, title, photos, policies, category, or item specifics write is performed.
     *
     * @return array<string,mixed>
     */
    public function readOnlyInventoryOfferForDescriptionRevise(?string $offerId, ?string $sku, ?string $listingId = null): array
    {
        $readiness = $this->getAccountReadiness();
        $baseUrl = rtrim((string) $this->account?->api_base_url, '/');
        $marketplaceId = $this->marketplaceId();
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId];
        $base = [
            'ok' => false,
            'read_only' => true,
            'api_endpoint_family' => 'inventory_offer.read_only',
            'revise_api_family' => 'inventory_offer',
            'inventory_sku' => $sku,
            'offer_id' => $offerId,
            'listing_id' => $listingId,
            'marketplace_id' => $marketplaceId,
            'can_revise_description_via_inventory_offer' => false,
            'blocker' => null,
            'inventory_offer_payload_fields_required' => ['full offer payload required by PUT /sell/inventory/v1/offer/{offerId}; listingDescription is only one field inside the offer payload'],
            'would_send_payload_keys' => [],
            'warnings' => ['Read-only Inventory/Offer API diagnostics only; no write, publish, revise, relist, end, stock, price, title, photos, policies, category, item specifics, or local mutation is performed.'],
            'blockers' => $readiness['blockers'] ?? [],
        ];
        if ($base['blockers'] !== []) return array_merge($base, ['blocker' => 'inventory_offer_api_not_ready']);
        if (blank($offerId) && blank($sku)) return array_merge($base, ['blocker' => 'missing_offer_id_or_inventory_sku']);

        $token = $this->accessToken();
        if (blank($token)) return array_merge($base, ['blocker' => 'missing_access_token', 'blockers' => ['Credential access_token is missing.']]);

        $offerPayload = null;
        $offerHttpStatus = null;
        if (filled($offerId)) {
            $response = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(20)->get($baseUrl.'/sell/inventory/v1/offer/'.rawurlencode((string) $offerId));
            $offerHttpStatus = $response->status();
            $json = $response->json();
            $offerPayload = is_array($json) ? $json : null;
        } elseif (filled($sku)) {
            $response = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(20)->get($baseUrl.'/sell/inventory/v1/offer', ['sku' => (string) $sku, 'marketplace_id' => $marketplaceId]);
            $offerHttpStatus = $response->status();
            $json = $response->json();
            $offers = is_array($json) ? array_values(array_filter($json['offers'] ?? [], 'is_array')) : [];
            $offerPayload = $offers[0] ?? null;
            $offerId = isset($offerPayload['offerId']) ? (string) $offerPayload['offerId'] : $offerId;
        }

        $offerKeys = is_array($offerPayload) ? array_keys($offerPayload) : [];
        $listingDescriptionPresent = is_array($offerPayload) && array_key_exists('listingDescription', $offerPayload);

        return array_merge($base, [
            'ok' => $offerPayload !== null,
            'offer_http_status' => $offerHttpStatus,
            'offer_id' => $offerId,
            'inventory_sku' => $sku ?: (is_array($offerPayload) ? ($offerPayload['sku'] ?? null) : null),
            'listing_id' => $listingId ?: (is_array($offerPayload) ? ($offerPayload['listingId'] ?? null) : null),
            'offer_payload_keys' => $offerKeys,
            'offer_payload_listingDescription_present' => $listingDescriptionPresent,
            'offer_payload_listingDescription_length' => $listingDescriptionPresent ? mb_strlen((string) $offerPayload['listingDescription']) : 0,
            'would_send_payload_keys' => $offerKeys,
            'can_revise_description_via_inventory_offer' => false,
            'blocker' => $offerPayload === null ? 'cannot_read_inventory_offer' : 'cannot_revise_description_only_for_inventory_offer',
        ]);
    }

    /** @param array{listingDescription:string} $payload */
    public function reviseInventoryOfferDescriptionOnly(?string $offerId, ?string $sku, array $payload, ?array $offerPayload = null): array
    {
        return [
            'ok' => false,
            'step' => 'reviseInventoryOfferDescriptionOnly',
            'api_endpoint_family' => 'inventory_offer.update_offer.description_only',
            'revise_api_family' => 'inventory_offer',
            'offer_id' => $offerId,
            'inventory_sku' => $sku,
            'error_code' => 'cannot_revise_description_only_for_inventory_offer',
            'error_message_safe' => 'eBay Inventory API updates offers with PUT /sell/inventory/v1/offer/{offerId}, which requires a full offer payload; refusing to write a payload that might change fields other than listingDescription.',
            'can_revise_description_via_inventory_offer' => false,
            'would_send_payload_keys' => is_array($offerPayload) ? array_keys($offerPayload) : array_keys($payload),
        ];
    }

    /**
     * Revise only the Trading API item description. The request intentionally contains
     * no price, stock, title, photos, policies, category, or item specifics fields.
     *
     * @param array{listingDescription:string} $payload
     * @return array<string,mixed>
     */
    public function reviseItemDescriptionOnly(string $itemId, array $payload): array
    {
        $description = (string) ($payload['listingDescription'] ?? '');
        $forbidden = array_values(array_intersect(array_keys($payload), ['price','pricingSummary','availableQuantity','quantity','stock','availability','listingPolicies','fulfillmentPolicyId','paymentPolicyId','returnPolicyId','merchantLocationKey','images','product','title','category','itemSpecifics']));
        if ($forbidden !== []) return ['ok' => false, 'step' => 'reviseItemDescriptionOnly', 'error' => 'forbidden_payload_keys_present', 'forbidden_keys' => $forbidden];
        if (blank($itemId) || blank($description)) return ['ok' => false, 'step' => 'reviseItemDescriptionOnly', 'error' => 'missing_item_id_or_listingDescription'];

        $credentials = $this->credentials();
        $token = $this->accessToken();
        if (blank($token)) return ['ok' => false, 'step' => 'reviseItemDescriptionOnly', 'error' => 'Credential access_token is missing.'];

        $siteId = (string) (($this->account?->api_settings ?? [])['site_id'] ?? ($this->marketplaceId() === 'EBAY_FR' ? '71' : '77'));
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<ReviseItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
            .'<RequesterCredentials><eBayAuthToken>'.htmlspecialchars($token, ENT_XML1).'</eBayAuthToken></RequesterCredentials>'
            .'<Item><ItemID>'.htmlspecialchars($itemId, ENT_XML1).'</ItemID><Description><![CDATA['.$this->safeCdata($description).']]></Description></Item>'
            .'</ReviseItemRequest>';

        try {
            $response = Http::withHeaders(array_filter([
                    'X-EBAY-API-CALL-NAME' => 'ReviseItem',
                    'X-EBAY-API-SITEID' => $siteId,
                    'X-EBAY-API-COMPATIBILITY-LEVEL' => (string) (($this->account?->api_settings ?? [])['trading_compatibility_level'] ?? '967'),
                    'X-EBAY-API-IAF-TOKEN' => $token,
                    'X-EBAY-API-APP-NAME' => $credentials['client_id'] ?? null,
                    'Content-Type' => 'text/xml',
                ]))
                ->timeout(20)
                ->withBody($xml, 'text/xml')
                ->post($this->tradingApiUrl());
        } catch (\Throwable $e) {
            return ['ok' => false, 'step' => 'reviseItemDescriptionOnly', 'api_endpoint_family' => 'trading.revise_item.description_only', 'error_code' => class_basename($e), 'error_message_safe' => 'Technical error during eBay Trading API ReviseItem.', 'trading_http_status' => null, 'marketplace_id' => $this->marketplaceId(), 'trading_site_id' => $siteId];
        }

        $ack = null;
        $errorMessage = null;
        $ebayErrors = [];
        if (trim($response->body()) !== '') {
            libxml_use_internal_errors(true);
            $parsed = simplexml_load_string($response->body());
            if ($parsed instanceof \SimpleXMLElement) {
                $parsed->registerXPathNamespace('e', 'urn:ebay:apis:eBLBaseComponents');
                $ack = (string) ($parsed->Ack ?? '');
                $errorNodes = $parsed->xpath('//e:Errors') ?: $parsed->xpath('//Errors') ?: [];
                foreach ($errorNodes as $node) {
                    $ebayErrors[] = array_filter(['short_message' => (string) ($node->ShortMessage ?? ''), 'long_message' => (string) ($node->LongMessage ?? ''), 'error_code' => (string) ($node->ErrorCode ?? ''), 'severity' => (string) ($node->SeverityCode ?? '')]);
                }
                $errorMessage = $ebayErrors[0]['long_message'] ?? $ebayErrors[0]['short_message'] ?? null;
            }
            libxml_clear_errors();
        }

        $ok = $response->successful() && in_array($ack, ['Success', 'Warning'], true);
        return [
            'ok' => $ok,
            'step' => 'reviseItemDescriptionOnly',
            'api_endpoint_family' => 'trading.revise_item.description_only',
            'http_status' => $response->status(),
            'trading_http_status' => $response->status(),
            'trading_ack' => $ack,
            'ebay_ack' => $ack,
            'ebay_errors' => $ebayErrors,
            'error_code' => $ok ? null : ($ebayErrors[0]['error_code'] ?? 'ebay_revise_item_failed'),
            'error_message_safe' => $ok ? null : ($errorMessage ?: 'eBay Trading API ReviseItem returned a non-success response.'),
            'request_id' => $response->header('x-ebay-c-request-id') ?: $response->header('rlogid'),
            'marketplace_id' => $this->marketplaceId(),
            'trading_site_id' => $siteId,
        ];
    }

    private function safeCdata(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }

    private function tradingApiUrl(): string
    {
        $settings = is_array($this->account?->api_settings) ? $this->account->api_settings : [];
        if (filled($settings['trading_api_url'] ?? null)) return (string) $settings['trading_api_url'];
        $baseUrl = (string) $this->account?->api_base_url;
        if (str_contains($baseUrl, 'sandbox')) return 'https://api.sandbox.ebay.com/ws/api.dll';
        return 'https://api.ebay.com/ws/api.dll';
    }

    private function normalizeBrowseItemStatus(int $httpStatus, array $payload): string
    {
        if ($httpStatus === 404) return 'not_found';
        if ($httpStatus === 410) return 'ended';
        if ($httpStatus < 200 || $httpStatus >= 300) return 'unavailable';
        $availability = strtoupper((string) data_get($payload, 'estimatedAvailabilities.0.estimatedAvailabilityStatus', ''));
        $endDate = $payload['itemEndDate'] ?? null;
        $endDateIsPast = filled($endDate) && strtotime((string) $endDate) !== false && strtotime((string) $endDate) < now()->timestamp;
        if ($endDateIsPast && ! in_array($availability, ['IN_STOCK', 'LIMITED_STOCK'], true)) return 'ended';
        if (in_array($availability, ['IN_STOCK', 'LIMITED_STOCK'], true)) return 'active';
        if (in_array($availability, ['OUT_OF_STOCK', 'UNAVAILABLE'], true)) return 'inactive';
        return filled($payload['itemId'] ?? null) ? 'active' : 'unknown';
    }

    private function formatBusinessPolicy(array $policy): array
    {
        return array_filter([
            'id' => $policy['fulfillmentPolicyId'] ?? $policy['paymentPolicyId'] ?? $policy['returnPolicyId'] ?? $policy['policyId'] ?? null,
            'name' => $policy['name'] ?? null,
            'categoryTypes' => $policy['categoryTypes'] ?? null,
            'marketplaceId' => $policy['marketplaceId'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function accessToken(): string
    {
        if (! $this->account) return (string) ($this->credentials()['access_token'] ?? '');
        $result = app(OAuthTokenManager::class)->ensureValidToken($this->account);
        return (string) ($result['access_token'] ?? ($this->credentials()['access_token'] ?? ''));
    }

    private function getWithAuthRetry(string $url, array $query = [], array $headers = [], int $timeout = 20)
    {
        $response = Http::withToken($this->accessToken())->withHeaders($headers)->acceptJson()->timeout($timeout)->get($url, $query);
        if ($response->status() === 401 && $this->account) {
            $refresh = app(OAuthTokenManager::class)->refresh($this->account);
            if (($refresh['ok'] ?? false) === true) {
                $response = Http::withToken((string) $refresh['access_token'])->withHeaders($headers)->acceptJson()->timeout($timeout)->get($url, $query);
            }
        }
        return $response;
    }


    private function postWithAuthRetry(string $url, array $payload = [], array $headers = [], int $timeout = 20)
    {
        $response = Http::withToken($this->accessToken())->withHeaders($headers)->acceptJson()->asJson()->timeout($timeout)->post($url, $payload);
        if ($response->status() === 401 && $this->account) {
            $refresh = app(OAuthTokenManager::class)->refresh($this->account);
            if (($refresh['ok'] ?? false) === true) {
                $response = Http::withToken((string) $refresh['access_token'])->withHeaders($headers)->acceptJson()->asJson()->timeout($timeout)->post($url, $payload);
            }
        }
        return $response;
    }

    private function refreshAccessToken(): string
    {
        if (! $this->account) return (string) ($this->credentials()['access_token'] ?? '');
        $result = app(OAuthTokenManager::class)->refresh($this->account);
        return (string) ($result['access_token'] ?? ($this->credentials()['access_token'] ?? ''));
    }


    /**
     * Read-only existence check for a guarded create-only Inventory API publish.
     * No inventory item, offer, listing, stock, price, description, relist, revise, end, or batch write is performed.
     *
     * @return array<string,mixed>
     */
    public function readOnlyInventoryAndOfferExistence(string $sku): array
    {
        $readiness = $this->getAccountReadiness();
        $baseUrl = rtrim((string) $this->account?->api_base_url, '/');
        $marketplaceId = $this->marketplaceId();
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId];
        $base = [
            'ok' => false,
            'read_only' => true,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'inventory_item_exists' => false,
            'offer_exists' => false,
            'offer_id' => null,
            'listing_id' => null,
            'inventory_http_status' => null,
            'offer_http_status' => null,
            'blockers' => $readiness['blockers'] ?? [],
        ];
        if ($base['blockers'] !== []) return $base + ['blocker' => 'inventory_offer_api_not_ready'];
        if (blank($sku)) return $base + ['blocker' => 'missing_sku', 'blockers' => ['missing_sku']];

        $token = $this->accessToken();
        if (blank($token)) return $base + ['blocker' => 'missing_access_token', 'blockers' => ['Credential access_token is missing.']];

        $inventoryResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(20)
            ->get($baseUrl.'/sell/inventory/v1/inventory_item/'.rawurlencode($sku));
        $offerResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(20)
            ->get($baseUrl.'/sell/inventory/v1/offer', ['sku' => $sku, 'marketplace_id' => $marketplaceId]);
        $offerJson = $offerResponse->json();
        $offers = is_array($offerJson) ? array_values(array_filter($offerJson['offers'] ?? [], 'is_array')) : [];
        $offer = $offers[0] ?? null;

        $inventoryOk = $inventoryResponse->successful() || $inventoryResponse->status() === 404;
        $offerOk = $offerResponse->successful() || $offerResponse->status() === 404;

        return array_merge($base, [
            'ok' => $inventoryOk && $offerOk,
            'inventory_http_status' => $inventoryResponse->status(),
            'offer_http_status' => $offerResponse->status(),
            'inventory_item_exists' => $inventoryResponse->successful(),
            'offer_exists' => $offer !== null,
            'offer_id' => is_array($offer) ? ($offer['offerId'] ?? null) : null,
            'listing_id' => is_array($offer) ? ($offer['listingId'] ?? null) : null,
            'offer_count' => count($offers),
            'blockers' => array_values(array_filter([
                $inventoryOk ? null : 'inventory_item_lookup_failed',
                $offerOk ? null : 'offer_lookup_failed',
            ])),
        ]);
    }

    public function readOnlyInventoryOfferListingDiagnostics(string $sku, ?string $offerId = null, ?string $listingId = null): array
    {
        $existence = $this->readOnlyInventoryAndOfferExistence($sku);
        $readiness = $this->getAccountReadiness();
        $baseUrl = rtrim((string) $this->account?->api_base_url, '/');
        $marketplaceId = $this->marketplaceId();
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId];
        $token = $this->accessToken();
        $offerId = $offerId ?: ($existence['offer_id'] ?? null);
        $listingId = $listingId ?: ($existence['listing_id'] ?? null);
        $result = $existence + [
            'read_only_api_check' => 'performed',
            'offer_id' => $offerId,
            'listing_id' => $listingId,
            'inventory_item_status' => ($existence['inventory_item_exists'] ?? false) ? 'exists' : 'not_found',
            'offer_status' => null,
            'listing_status' => null,
            'is_publicly_visible' => false,
            'public_item_url' => null,
            'public_item_url_source' => null,
            'read_only_ebay_api_responses' => [],
            'blockers' => $readiness['blockers'] ?? [],
        ];
        if (($result['blockers'] ?? []) !== [] || blank($token)) return $result;

        if (filled($offerId)) {
            $offerResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(20)->get($baseUrl.'/sell/inventory/v1/offer/'.rawurlencode((string) $offerId));
            $offerJson = $offerResponse->json();
            $offer = is_array($offerJson) ? $offerJson : [];
            $listingId = $listingId ?: ($offer['listingId'] ?? null);
            $result['offer_status'] = $offer['status'] ?? $offer['listingStatus'] ?? null;
            $result['listing_id'] = $listingId;
            $result['read_only_ebay_api_responses']['get_offer'] = ['http_status' => $offerResponse->status(), 'json' => $offer];
        }

        if (filled($listingId)) {
            $legacyResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(20)
                ->get($baseUrl.'/buy/browse/v1/item/get_item_by_legacy_id', ['legacy_item_id' => (string) $listingId]);
            $legacyJson = $legacyResponse->json();
            $item = is_array($legacyJson) ? $legacyJson : [];
            $result['read_only_ebay_api_responses']['browse_get_item_by_legacy_id'] = ['http_status' => $legacyResponse->status(), 'json' => $item];
            $result['listing_status'] = $item['itemEndDate'] ?? null ? 'ENDED_OR_HAS_END_DATE' : ($legacyResponse->successful() ? 'PUBLICLY_READABLE' : null);
            $result['public_item_url'] = $item['itemWebUrl'] ?? $item['itemAffiliateWebUrl'] ?? null;
            $result['public_item_url_source'] = filled($result['public_item_url']) ? 'buy_browse_get_item_by_legacy_id.itemWebUrl' : null;
            $result['is_publicly_visible'] = $legacyResponse->successful() && filled($result['public_item_url']);
        }

        $result['warnings'] = array_values(array_filter([
            ($result['is_publicly_visible'] ?? false) ? null : 'publishOffer_success_does_not_guarantee_public_url_is_immediately_visible_or_browse_readable',
            blank($result['public_item_url']) ? 'public_item_url_not_returned_by_read_only_ebay_api' : null,
        ]));

        return $result;
    }

    public function reviseInventoryOffer(string $sku, string $offerId, array $inventoryPayload, array $offerPayload, ?string $contentLanguage = null): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $token = $this->accessToken();
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => (string) ($offerPayload['marketplaceId'] ?? $this->marketplaceId())];
        if (filled($contentLanguage)) $headers['Content-Language'] = (string) $contentLanguage;

        $inventoryResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->asJson()->timeout(30)
            ->put($base.'/sell/inventory/v1/inventory_item/'.rawurlencode($sku), $inventoryPayload);
        if (! $inventoryResponse->successful()) return $this->writeResult('reviseInventoryItem', $inventoryResponse, $headers);

        $offerPayload = array_diff_key($offerPayload, ['offerId' => true]);
        $offerResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->asJson()->timeout(30)
            ->put($base.'/sell/inventory/v1/offer/'.rawurlencode($offerId), $offerPayload);
        $offerJson = $offerResponse->json();

        return [
            'ok' => $offerResponse->successful(),
            'step' => 'updateOffer',
            'http_status' => $offerResponse->status(),
            'inventory_http_status' => $inventoryResponse->status(),
            'offer_http_status' => $offerResponse->status(),
            'offer_id' => $offerId,
            'listing_id' => is_array($offerJson) ? ($offerJson['listingId'] ?? $offerPayload['listingId'] ?? null) : ($offerPayload['listingId'] ?? null),
            'json' => is_array($offerJson) ? $offerJson : [],
            'request_id' => $offerResponse->header('x-ebay-c-request-id') ?: $offerResponse->header('rlogid'),
            'content_language' => $headers['Content-Language'] ?? null,
            'marketplace_id' => $headers['X-EBAY-C-MARKETPLACE-ID'],
        ];
    }

    public function publishInventoryOffer(string $sku, array $inventoryPayload, array $offerPayload, ?string $contentLanguage = null): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $token = $this->accessToken();
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => (string) ($offerPayload['marketplaceId'] ?? $this->marketplaceId())];
        if (filled($contentLanguage)) $headers['Content-Language'] = (string) $contentLanguage;
        $inventoryResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->asJson()->timeout(30)
            ->put($base.'/sell/inventory/v1/inventory_item/'.rawurlencode($sku), $inventoryPayload);
        if (! $inventoryResponse->successful()) return $this->writeResult('createOrReplaceInventoryItem', $inventoryResponse, $headers);

        $offerResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->asJson()->timeout(30)
            ->post($base.'/sell/inventory/v1/offer', $offerPayload);
        if (! $offerResponse->successful()) return $this->writeResult('createOffer', $offerResponse, $headers) + ['inventory_http_status' => $inventoryResponse->status(), 'offer_id' => $this->offerIdFromErrorResponse($offerResponse->json())];

        $offerJson = $offerResponse->json();
        $offerId = is_array($offerJson) ? (string) ($offerJson['offerId'] ?? '') : '';
        if ($offerId === '') return ['ok' => false, 'step' => 'createOffer', 'http_status' => $offerResponse->status(), 'json' => is_array($offerJson) ? $offerJson : [], 'error' => 'eBay createOffer response did not contain offerId.'];

        $publishResponse = Http::withToken($token)->withHeaders($headers)->acceptJson()->asJson()->timeout(30)
            ->post($base.'/sell/inventory/v1/offer/'.rawurlencode($offerId).'/publish');
        $publishJson = $publishResponse->json();

        return [
            'ok' => $publishResponse->successful(),
            'step' => 'publishOffer',
            'http_status' => $publishResponse->status(),
            'inventory_http_status' => $inventoryResponse->status(),
            'offer_http_status' => $offerResponse->status(),
            'offer_id' => $offerId,
            'listing_id' => is_array($publishJson) ? ($publishJson['listingId'] ?? null) : null,
            'json' => is_array($publishJson) ? $publishJson : [],
            'request_id' => $publishResponse->header('x-ebay-c-request-id') ?: $publishResponse->header('rlogid'),
            'content_language' => $headers['Content-Language'] ?? null,
            'marketplace_id' => $headers['X-EBAY-C-MARKETPLACE-ID'],
        ];
    }

    private function offerIdFromErrorResponse(mixed $json): ?string
    {
        $messages = [];
        foreach ((array) data_get($json, 'errors', []) as $error) {
            if (! is_array($error)) continue;
            foreach (['message', 'longMessage'] as $key) {
                if (filled($error[$key] ?? null)) $messages[] = (string) $error[$key];
            }
            foreach ((array) ($error['parameters'] ?? []) as $parameter) {
                $value = is_array($parameter) ? ($parameter['value'] ?? null) : null;
                if (filled($value) && preg_match('/^\d{6,}$/', (string) $value)) return (string) $value;
            }
        }

        $text = implode(' ', $messages);
        if (preg_match('/offerId\s*[=:]\s*(\d{6,})/i', $text, $matches)) return $matches[1];
        if (preg_match('/Preisangebot-Entit(?:ä|ae)t existiert bereits.*?(\d{6,})/iu', $text, $matches)) return $matches[1];

        return null;
    }

    private function writeResult(string $step, $response, array $headers = []): array
    {
        $json = $response->json();
        return ['ok' => false, 'step' => $step, 'http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'request_id' => $response->header('x-ebay-c-request-id') ?: $response->header('rlogid'), 'content_language' => $headers['Content-Language'] ?? null, 'marketplace_id' => $headers['X-EBAY-C-MARKETPLACE-ID'] ?? null];
    }

    protected function extractOffers(array $payload): array
    {
        $rows = $payload['inventoryItems'] ?? $payload['inventoryItem'] ?? [];
        return array_values(array_map(fn ($row) => [
            'external_offer_id' => (string) ($row['sku'] ?? ''), 'title' => $row['product']['title'] ?? null, 'sku' => $row['sku'] ?? null,
            'price' => null, 'quantity' => $row['availability']['shipToLocationAvailability']['quantity'] ?? null,
            'status' => null, 'url' => null,
        ], array_filter($rows, 'is_array')));
    }
}

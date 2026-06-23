<?php

namespace App\Services\Marketplace\Api;

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
        $response = Http::withToken($this->accessToken())->acceptJson()->timeout(15)->get($this->endpointUsed($limit));
        $json = $response->json();
        $error = in_array($response->status(), [401, 403], true) ? 'OAuth token expired, unauthorized, forbidden, or missing Inventory API scope.' : null;
        return ['http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'api_ok' => $response->successful(), 'error' => $error];
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

    public function compatibilityPropertyValues(string $categoryId, string $property, array $filters = []): array
    {
        $readiness = $this->getAccountReadiness();
        $treeId = $this->categoryTreeId();
        $base = ['ok' => false, 'property' => $property, 'values_count' => 0, 'values_sample' => [], 'blockers' => $readiness['blockers'] ?? [], 'warnings' => [], 'read_only' => true];
        if ($base['blockers'] !== []) return $base;
        $params = ['category_id' => $categoryId, 'compatibility_property' => $property] + $filters;
        $json = $this->cachedEbayGet('taxonomy.compatibility_values.'.$treeId.'.'.$categoryId.'.'.md5(json_encode($params)), '/commerce/taxonomy/v1/category_tree/'.$treeId.'/get_compatibility_property_values', $params);
        if (! ($json['ok'] ?? false)) return array_merge($base, ['blockers' => ['Could not read eBay compatibility property values (HTTP '.($json['http_status'] ?? 'n/a').').']]);
        $payload = is_array($json['json'] ?? null) ? $json['json'] : [];
        $values = array_values(array_filter($payload['compatibilityPropertyValues'] ?? $payload['values'] ?? [], 'is_array'));
        $base['values_count'] = count($values);
        $base['values_sample'] = array_slice(array_map(fn ($v) => $v['value'] ?? $v['localizedValue'] ?? $v, $values), 0, 50);
        $base['ok'] = true;
        return $base;
    }

    private function cachedEbayGet(string $key, string $path, array $query): array
    {
        return Cache::remember('ebay_readonly:'.$key, now()->addHours(24), function () use ($path, $query) {
            $response = Http::withToken($this->accessToken())->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId()])->acceptJson()->timeout(20)->get(rtrim((string) $this->account?->api_base_url, '/').$path, $query);
            return ['ok' => $response->successful(), 'http_status' => $response->status(), 'json' => is_array($response->json()) ? $response->json() : []];
        });
    }

    private function marketplaceId(): string { return (string) (($this->account?->api_settings ?? [])['marketplace_id'] ?? ($this->channel === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE')); }
    private function categoryTreeId(): string { $settings = is_array($this->account?->api_settings) ? $this->account->api_settings : []; return (string) ($settings['category_tree_id'] ?? $settings['site_id'] ?? ($this->channel === 'ebay_fr' ? '71' : '77')); }


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
        $credentials = $this->credentials();
        $expiresAt = isset($credentials['expires_at']) ? strtotime((string) $credentials['expires_at']) : null;
        if (! blank($credentials['access_token'] ?? null) && (! $expiresAt || $expiresAt > now()->addMinute()->timestamp)) return (string) $credentials['access_token'];
        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): string
    {
        $credentials = $this->credentials();
        if (blank($credentials['client_id'] ?? null) || blank($credentials['client_secret'] ?? null) || blank($credentials['refresh_token'] ?? null)) return (string) ($credentials['access_token'] ?? '');

        $response = Http::asForm()->withBasicAuth((string) $credentials['client_id'], (string) $credentials['client_secret'])->acceptJson()->timeout(20)->post(EbayOAuthConfig::tokenUrl((string) $this->account?->api_base_url), [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $credentials['refresh_token'],
            'scope' => (string) ($credentials['scopes'] ?? EbayOAuthConfig::scopeString()),
        ]);
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) || blank($payload['access_token'] ?? null)) return (string) ($credentials['access_token'] ?? '');

        $updated = array_merge($credentials, [
            'access_token' => (string) $payload['access_token'],
            'expires_at' => EbayOAuthConfig::tokenExpiresAt($payload['expires_in'] ?? null),
            'token_type' => (string) ($payload['token_type'] ?? ($credentials['token_type'] ?? '')),
            'scopes' => $payload['scope'] ?? ($credentials['scopes'] ?? EbayOAuthConfig::scopeString()),
        ]);
        if (filled($payload['refresh_token'] ?? null)) $updated['refresh_token'] = (string) $payload['refresh_token'];
        $this->account?->forceFill(['api_credentials' => $updated])->save();

        return (string) $updated['access_token'];
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

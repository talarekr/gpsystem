<?php

namespace App\Services\Marketplace\Api;

use App\Services\Marketplace\OAuthTokenManager;
use App\Support\Marketplace\AllegroOAuthConfig;
use Illuminate\Support\Facades\Http;

class AllegroApiClient extends AbstractMarketplaceApiClient
{
    protected function requiredCredentialKeys(): array { return ['access_token']; }
    protected function optionalCredentialKeys(): array { return ['client_id', 'client_secret', 'refresh_token']; }
    protected function endpointPath(): string { return '/sale/offers'; }


    public function refreshAccessToken(): array
    {
        return app(OAuthTokenManager::class)->refresh($this->account);
    }

    public function legacyRefreshAccessToken(): array
    {
        $credentials = $this->credentials();
        $clientId = (string) ($credentials['client_id'] ?? '');
        $clientSecret = (string) ($credentials['client_secret'] ?? '');
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if (! $this->account || $clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return ['ok' => false, 'status' => 'not_ready', 'message' => 'Allegro refresh token prerequisites are missing.'];
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->acceptJson()
            ->timeout(20)
            ->post(AllegroOAuthConfig::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if (! $response->successful()) {
            $this->storeStatus('failed', 'Allegro access token refresh failed without exposing credentials.');
            return ['ok' => false, 'status' => 'failed', 'http_status' => $response->status()];
        }

        $payload = $response->json();
        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            $this->storeStatus('failed', 'Allegro access token refresh response was missing an access token.');
            return ['ok' => false, 'status' => 'invalid_response', 'http_status' => $response->status()];
        }

        $this->account->forceFill([
            'api_credentials' => array_merge($credentials, [
                'access_token' => (string) $payload['access_token'],
                'refresh_token' => (string) ($payload['refresh_token'] ?? $refreshToken),
                'token_type' => (string) ($payload['token_type'] ?? ($credentials['token_type'] ?? '')),
                'expires_in' => $payload['expires_in'] ?? ($credentials['expires_in'] ?? null),
                'access_token_expires_at' => AllegroOAuthConfig::tokenExpiresAt($payload['expires_in'] ?? null),
                'refreshed_at' => now()->toISOString(),
            ]),
            'last_connection_check_at' => now(),
            'last_connection_status' => 'ok',
            'last_connection_message' => 'Allegro access token refreshed securely.',
        ])->save();

        return ['ok' => true, 'status' => 'ok', 'http_status' => $response->status()];
    }



    public function endOffer(string $offerId): array
    {
        $endpoint = rtrim((string) $this->account?->api_base_url, '/').'/sale/product-offers/'.rawurlencode($offerId);
        $payload = ['publication' => ['status' => 'ENDED']];
        $response = Http::withToken((string) $this->credentials()['access_token'])
            ->accept('application/vnd.allegro.public.v1+json')
            ->asJson()
            ->timeout(20)
            ->patch($endpoint, $payload);
        $json = $response->json();
        $body = is_array($json) ? $json : [];

        return [
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'message' => $response->successful() ? 'Allegro offer ended.' : 'Allegro end offer failed.',
            'request_summary' => ['endpoint' => 'PATCH /sale/product-offers/{offerId}', 'offer_id' => $offerId, 'publication.status' => 'ENDED'],
            'response_summary' => ['top_level_keys' => array_slice(array_keys($body), 0, 20), 'status' => $body['publication']['status'] ?? $body['status'] ?? null],
        ];
    }

    /**
     * Read-only lookup of seller sales settings required by /sale/product-offers.
     * Allegro product-offers expects resource identifiers, not display names, for
     * delivery.shippingRates.id and afterSalesServices.*.id.
     */
    public function resolveSalesSettingsByName(string $name): array
    {
        return $this->resolveSalesSettingsByNames(['shippingRates' => $name, 'returnPolicy' => $name, 'impliedWarranty' => $name, 'warranty' => $name]);
    }

    /** @param array<string, string> $names */
    public function resolveSalesSettingsByNames(array $names): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $token = (string) $this->credentials()['access_token'];
        $endpoints = [
            'shippingRates' => ['/sale/shipping-rates', ['shippingRates', 'rates']],
            'returnPolicy' => ['/after-sales-service-conditions/return-policies', ['returnPolicies', 'returnPolicyList', 'policies']],
            'impliedWarranty' => ['/after-sales-service-conditions/implied-warranties', ['impliedWarranties', 'impliedWarrantyList', 'warranties']],
            'warranty' => ['/after-sales-service-conditions/warranties', ['warranties', 'warrantyList']],
        ];

        $resolved = [];
        foreach ($endpoints as $key => [$path, $listKeys]) {
            $response = Http::withToken($token)
                ->accept('application/vnd.allegro.public.v1+json')
                ->timeout(20)
                ->get($base.$path);
            $json = $response->json();
            $rows = $this->extractSalesSettingsRows(is_array($json) ? $json : [], $listKeys);
            $searchedName = (string) ($names[$key] ?? '');
            $match = collect($rows)->first(fn (array $row) => strcasecmp(trim((string) ($row['name'] ?? '')), trim($searchedName)) === 0 && $this->isActiveSalesSettingsRow($row));
            $resolved[$key] = [
                'http_status' => $response->status(),
                'ok' => $response->successful(),
                'searched_name' => $searchedName,
                'id' => is_array($match) ? ($match['id'] ?? null) : null,
                'found' => is_array($match) && filled($match['id'] ?? null),
                'reason' => $response->successful() ? (is_array($match) ? null : 'not_found_or_inactive') : 'read_failed',
                'active' => is_array($match) ? $this->isActiveSalesSettingsRow($match) : null,
            ];
        }

        return $resolved;
    }

    private function isActiveSalesSettingsRow(array $row): bool
    {
        foreach (['status', 'state'] as $key) {
            if (! array_key_exists($key, $row) || blank($row[$key])) continue;
            return in_array(strtolower((string) $row[$key]), ['active', 'enabled'], true);
        }

        if (array_key_exists('enabled', $row)) return (bool) $row['enabled'];
        if (array_key_exists('active', $row)) return (bool) $row['active'];

        return true;
    }

    private function extractSalesSettingsRows(array $payload, array $listKeys): array
    {
        foreach ($listKeys as $key) {
            if (is_array($payload[$key] ?? null)) return array_values(array_filter($payload[$key], 'is_array'));
        }

        if (array_is_list($payload)) return array_values(array_filter($payload, 'is_array'));

        return [];
    }

    /**
     * Read-only GPSR responsible producers lookup for diagnostics.
     */
    public function responsibleProducers(): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $response = Http::withToken((string) $this->credentials()['access_token'])
            ->accept('application/vnd.allegro.public.v1+json')
            ->timeout(20)
            ->get($base.'/sale/responsible-producers');
        $json = $response->json();

        return [
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'items' => $this->extractResponsibleProducerRows(is_array($json) ? $json : []),
            'error' => in_array($response->status(), [401, 403], true)
                ? 'Unauthorized or forbidden. Check Allegro token validity and scopes; no secrets are exposed here.'
                : ($response->successful() ? null : 'Responsible producers lookup failed.'),
            'request_id' => $response->header('trace-id') ?: $response->header('x-request-id'),
        ];
    }

    private function extractResponsibleProducerRows(array $payload): array
    {
        $rows = $payload['responsibleProducers'] ?? $payload['producers'] ?? $payload['items'] ?? $payload['data'] ?? (array_is_list($payload) ? $payload : []);
        return array_values(array_map(fn (array $row): array => array_filter([
            'id' => $row['id'] ?? null,
            'name' => $row['name'] ?? $row['customName'] ?? null,
            'producerData' => $row['producerData'] ?? null,
            'contact' => $row['contact'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''), array_filter($rows, 'is_array')));
    }


    public function compatibilitySupportedCategories(): array
    {
        $response = $this->getWithAuthRetry($this->absoluteUrl('/sale/compatibility-list/supported-categories'));
        $json = $response->json();
        return ['ok' => $response->successful(), 'http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'request_id' => $response->header('trace-id') ?: $response->header('x-request-id'), 'error' => $response->successful() ? null : 'Compatibility supported categories lookup failed.'];
    }

    public function searchProducts(string $categoryId, string $phrase): array
    {
        $response = $this->getWithAuthRetry($this->absoluteUrl('/sale/products'), array_filter(['category.id' => $categoryId, 'phrase' => $phrase, 'limit' => 20], fn ($value) => filled($value)));
        $json = $response->json();
        return ['ok' => $response->successful(), 'http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'products' => is_array($json) ? array_values(array_filter($json['products'] ?? $json['items'] ?? [], 'is_array')) : [], 'request_id' => $response->header('trace-id') ?: $response->header('x-request-id'), 'error' => $response->successful() ? null : 'Allegro product catalog search failed.'];
    }

    public function compatibleProducts(string $phrase, string $type = 'CAR'): array
    {
        $response = $this->getWithAuthRetry($this->absoluteUrl('/sale/compatible-products'), array_filter(['type' => $type, 'phrase' => $phrase], fn ($value) => filled($value)));
        $json = $response->json();
        $items = is_array($json) ? array_values(array_filter($json['compatibleProducts'] ?? $json['products'] ?? $json['items'] ?? [], 'is_array')) : [];

        return [
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'json' => is_array($json) ? $json : [],
            'items' => array_map(fn (array $item): array => array_filter([
                'id' => $item['id'] ?? null,
                'text' => $item['text'] ?? $item['name'] ?? null,
                'type' => $item['type'] ?? null,
                'details' => $item['details'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''), $items),
            'request_id' => $response->header('trace-id') ?: $response->header('x-request-id'),
            'error' => $response->successful() ? null : 'Compatible products lookup failed.',
        ];
    }

    public function product(string $productId): array
    {
        $response = $this->getWithAuthRetry($this->absoluteUrl('/sale/products/'.rawurlencode($productId)));
        $json = $response->json();
        return ['ok' => $response->successful(), 'http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'request_id' => $response->header('trace-id') ?: $response->header('x-request-id'), 'error' => $response->successful() ? null : 'Allegro product details lookup failed.'];
    }

    public function compatibilityListSuggestions(?string $productId = null, ?string $offerId = null, ?string $language = null): array
    {
        $query = array_filter(['product.id' => $productId, 'offer.id' => $offerId, 'language' => $language], fn ($value) => filled($value));
        $response = $this->getWithAuthRetry($this->absoluteUrl('/sale/compatibility-list-suggestions'), $query);
        $json = $response->json();
        return ['ok' => $response->successful(), 'http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'request_id' => $response->header('trace-id') ?: $response->header('x-request-id')];
    }

    public function createProductOffer(array $payload): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $response = Http::withToken((string) $this->credentials()['access_token'])
            ->accept('application/vnd.allegro.public.v1+json')
            ->contentType('application/vnd.allegro.public.v1+json')
            ->timeout(30)
            ->post($base.'/sale/product-offers', $payload);
        $json = $response->json();
        return [
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'offer_id' => is_array($json) ? ($json['id'] ?? data_get($json, 'offer.id')) : null,
            'operation_location' => $response->header('Location'),
            'json' => is_array($json) ? $json : [],
            'request_id' => $response->header('trace-id') ?: $response->header('x-request-id'),
        ];
    }


    public function updateProductOfferDescription(string $offerId, array $description): array
    {
        $response = Http::withToken((string) $this->credentials()['access_token'])
            ->accept('application/vnd.allegro.public.v1+json')
            ->contentType('application/vnd.allegro.public.v1+json')
            ->timeout(30)
            ->patch($this->absoluteUrl('/sale/product-offers/'.rawurlencode($offerId)), [
                'description' => $description,
            ]);
        $json = $response->json();

        return [
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'json' => is_array($json) ? $json : [],
            'request_id' => $response->header('trace-id') ?: $response->header('x-request-id'),
        ];
    }

    public function productOfferOperationStatus(string $location): array
    {
        $response = Http::withToken((string) $this->credentials()['access_token'])->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get($this->absoluteUrl($location));
        return ['ok' => $response->successful(), 'http_status' => $response->status(), 'json' => is_array($response->json()) ? $response->json() : [], 'request_id' => $response->header('trace-id') ?: $response->header('x-request-id')];
    }

    public function productOffer(string $offerId): array
    {
        $response = Http::withToken((string) $this->credentials()['access_token'])
            ->accept('application/vnd.allegro.public.v1+json')
            ->timeout(20)
            ->get($this->absoluteUrl('/sale/product-offers/'.rawurlencode($offerId)));
        return ['ok' => $response->successful(), 'http_status' => $response->status(), 'json' => is_array($response->json()) ? $response->json() : [], 'request_id' => $response->header('trace-id') ?: $response->header('x-request-id')];
    }

    private function accessToken(): string
    {
        if (! $this->account) return (string) ($this->credentials()['access_token'] ?? '');
        $result = app(OAuthTokenManager::class)->ensureValidToken($this->account);
        return (string) ($result['access_token'] ?? ($this->credentials()['access_token'] ?? ''));
    }

    private function getWithAuthRetry(string $url, array $query = [], int $timeout = 20)
    {
        $response = Http::withToken($this->accessToken())->accept('application/vnd.allegro.public.v1+json')->timeout($timeout)->get($url, $query);
        if ($response->status() === 401 && $this->account) {
            $refresh = app(OAuthTokenManager::class)->refresh($this->account);
            if (($refresh['ok'] ?? false) === true) {
                $response = Http::withToken((string) $refresh['access_token'])->accept('application/vnd.allegro.public.v1+json')->timeout($timeout)->get($url, $query);
            }
        }
        return $response;
    }

    private function absoluteUrl(string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) return $location;
        return rtrim((string) $this->account?->api_base_url, '/').'/'.ltrim($location, '/');
    }

    protected function requestSample(int $limit): array
    {
        $response = $this->getWithAuthRetry($this->endpointUsed($limit), [], 15);
        $json = $response->json();
        $error = in_array($response->status(), [401, 403], true) ? 'Access token expired, unauthorized, forbidden, or missing required scopes.' : null;
        return ['http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'api_ok' => $response->successful(), 'error' => $error];
    }

    protected function extractOffers(array $payload): array
    {
        $rows = $payload['offers'] ?? [];
        return array_values(array_map(fn ($row) => [
            'external_offer_id' => (string) ($row['id'] ?? ''), 'title' => $row['name'] ?? null,
            'sku' => $row['external']['id'] ?? $row['productSet'][0]['product']['id'] ?? null,
            'price' => $row['sellingMode']['price']['amount'] ?? null, 'quantity' => $row['stock']['available'] ?? null,
            'status' => $row['publication']['status'] ?? null, 'url' => $row['publication']['marketplaces']['base']['url'] ?? null,
        ], array_filter($rows, 'is_array')));
    }
}

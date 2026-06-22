<?php

namespace App\Services\Marketplace\Api;

use App\Support\Marketplace\EbayOAuthConfig;
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

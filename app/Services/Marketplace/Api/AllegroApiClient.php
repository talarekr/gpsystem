<?php

namespace App\Services\Marketplace\Api;

use Illuminate\Support\Facades\Http;

class AllegroApiClient extends AbstractMarketplaceApiClient
{
    protected function requiredCredentialKeys(): array { return ['access_token']; }
    protected function optionalCredentialKeys(): array { return ['client_id', 'client_secret', 'refresh_token']; }
    protected function endpointPath(): string { return '/sale/offers'; }

    protected function requestSample(int $limit): array
    {
        $response = Http::withToken((string) $this->credentials()['access_token'])->accept('application/vnd.allegro.public.v1+json')->timeout(15)->get($this->endpointUsed($limit));
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

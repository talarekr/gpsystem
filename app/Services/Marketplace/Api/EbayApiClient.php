<?php

namespace App\Services\Marketplace\Api;

use Illuminate\Support\Facades\Http;

class EbayApiClient extends AbstractMarketplaceApiClient
{
    protected function requiredCredentialKeys(): array { return ['access_token']; }
    protected function optionalCredentialKeys(): array { return ['client_id', 'client_secret', 'refresh_token']; }
    protected function endpointPath(): string { return '/sell/inventory/v1/inventory_item'; }

    protected function requestSample(int $limit): array
    {
        $response = Http::withToken((string) $this->credentials()['access_token'])->acceptJson()->timeout(15)->get($this->endpointUsed($limit));
        $json = $response->json();
        $error = in_array($response->status(), [401, 403], true) ? 'OAuth token expired, unauthorized, forbidden, or missing Inventory API scope.' : null;
        return ['http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'api_ok' => $response->successful(), 'error' => $error];
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

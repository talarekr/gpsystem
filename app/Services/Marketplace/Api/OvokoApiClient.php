<?php

namespace App\Services\Marketplace\Api;

use Illuminate\Support\Facades\Http;

class OvokoApiClient extends AbstractMarketplaceApiClient
{
    protected function requiredCredentialKeys(): array { return ['username', 'password', 'user_token']; }
    protected function optionalCredentialKeys(): array { return []; }
    protected function endpointPath(): string { return '/v2/get/parts'; }

    protected function requestSample(int $limit): array
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post($this->endpointUsed($limit).'&page=1', $this->credentials());
        $json = $response->json();
        $apiOk = $response->successful() && (($json['status_code'] ?? null) === 'R200' || ($json['status_code'] ?? null) === 200);
        return ['http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'api_ok' => $apiOk, 'error' => $json['msg'] ?? $json['message'] ?? null];
    }

    protected function extractOffers(array $payload): array
    {
        $rows = $payload['data'] ?? $payload['parts'] ?? [];
        if (! is_array($rows)) return [];
        return array_values(array_map(fn ($row) => [
            'external_offer_id' => (string) ($row['id'] ?? $row['part_id'] ?? $row['external_id'] ?? ''),
            'title' => $row['name'] ?? $row['title'] ?? null, 'sku' => $row['sku'] ?? $row['code'] ?? null,
            'price' => $row['price'] ?? null, 'quantity' => $row['quantity'] ?? $row['stock'] ?? null,
            'status' => $row['status'] ?? null, 'url' => $row['url'] ?? null,
        ], array_filter($rows, 'is_array')));
    }
}

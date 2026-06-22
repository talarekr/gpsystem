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

    public function fetchPartsPage(int $page, int $limit): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($this->endpointUsed($limit).'&page='.$page, $this->credentials());

        $json = $response->json();
        $payload = is_array($json) ? $json : [];
        $statusCode = $payload['status_code'] ?? null;
        $apiOk = $response->successful() && ($statusCode === 'R200' || $statusCode === 200);
        $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];

        return [
            'http_status' => $response->status(),
            'api_status_code' => $statusCode,
            'api_ok' => $apiOk,
            'error' => $payload['msg'] ?? $payload['message'] ?? null,
            'page' => $page,
            'limit' => $limit,
            'total_count' => is_numeric($pagination['total_count'] ?? null) ? (int) $pagination['total_count'] : null,
            'parts' => $this->extractOffers($payload),
        ];
    }

    protected function extractOffers(array $payload): array
    {
        $rows = $payload['data'] ?? $payload['parts'] ?? [];
        if (! is_array($rows)) return [];
        return array_values(array_map(fn ($row) => [
            'external_offer_id' => (string) ($row['id'] ?? $row['part_id'] ?? $row['ovoko_part_id'] ?? $row['external_id'] ?? ''),
            'title' => $row['name'] ?? $row['title'] ?? null,
            'sku' => $row['sku'] ?? $row['code'] ?? null,
            'price' => $row['price'] ?? $row['sell_price'] ?? null,
            'currency' => $row['currency'] ?? $row['price_currency'] ?? null,
            'quantity' => $row['quantity'] ?? $row['stock'] ?? $row['qty'] ?? null,
            'status' => $row['status'] ?? null,
            'url' => $row['url'] ?? null,
        ], array_filter($rows, 'is_array')));
    }
}

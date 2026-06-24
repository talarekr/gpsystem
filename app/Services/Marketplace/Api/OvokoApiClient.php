<?php

namespace App\Services\Marketplace\Api;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class OvokoApiClient extends AbstractMarketplaceApiClient
{
    public const MAX_PARTS_PAGE_LIMIT = 100;

    protected function requiredCredentialKeys(): array { return ['username', 'password', 'user_token']; }
    protected function optionalCredentialKeys(): array { return []; }
    protected function endpointPath(): string { return '/v2/get/parts'; }

    protected function requestSample(int $limit): array
    {
        $limit = $this->normalizePartsPageLimit($limit);
        $response = Http::asForm()->acceptJson()->timeout(15)->post($this->endpointUsed($limit, 1), $this->authFields());
        $json = $response->json();
        $apiOk = $response->successful() && (($json['status_code'] ?? null) === 'R200' || ($json['status_code'] ?? null) === 200);
        return ['http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'api_ok' => $apiOk, 'error' => $json['msg'] ?? $json['message'] ?? null];
    }

    public function fetchPartsPage(int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = $this->normalizePartsPageLimit($limit);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($this->endpointUsed($limit, $page), $this->authFields());

        $json = $response->json();
        $hasJsonPayload = is_array($json);
        $payload = $hasJsonPayload ? $json : [];
        $statusCode = $payload['status_code'] ?? null;
        $apiOk = $response->successful() && ($statusCode === 'R200' || $statusCode === 200);
        $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];

        $parts = $this->extractOffers($payload);

        return [
            'http_status' => $response->status(),
            'api_status_code' => $statusCode,
            'api_ok' => $apiOk,
            'has_json_payload' => $hasJsonPayload,
            'error' => $payload['msg'] ?? $payload['message'] ?? null,
            'page' => $page,
            'limit' => $limit,
            'endpoint_used' => $this->endpointUsed($limit, $page),
            'total_count' => is_numeric($pagination['total_count'] ?? null) ? (int) $pagination['total_count'] : null,
            'parts' => $parts,
            'diagnostics' => $this->safeDiagnostics($response->status(), $payload, $parts, $page, $limit),
        ];
    }

    public function safeDiagnostics(?int $httpStatus, array $payload, array $parts, int $page, int $limit, ?string $errorMessage = null): array
    {
        $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];

        return [
            'http_status' => $httpStatus,
            'ovoko_status_code' => $payload['status_code'] ?? null,
            'ovoko_status_message' => $payload['msg'] ?? ($payload['message'] ?? null),
            'ovoko_msg' => $payload['msg'] ?? null,
            'endpoint_used' => $this->endpointUsed($limit, $page),
            'request_page' => $page,
            'request_limit' => $limit,
            'request_method' => 'POST',
            'request_format' => 'form-data',
            'response_top_level_keys' => array_values(array_slice(array_keys($payload), 0, 30)),
            'response_data_count' => is_countable($payload['data'] ?? null) ? count($payload['data']) : count($parts),
            'response_pagination' => $pagination === [] ? null : Arr::only($pagination, ['page', 'limit', 'total_count', 'total_pages']),
            'error_message_safe' => $errorMessage ?? $payload['msg'] ?? ($payload['message'] ?? null),
        ];
    }

    public function safeExceptionDiagnostics(int $page, int $limit, string $message): array
    {
        return $this->safeDiagnostics(null, [], [], $page, $limit, $message);
    }

    protected function endpointUsed(int $limit, int $page = 1): string
    {
        return rtrim((string) $this->account?->api_base_url, '/').$this->endpointPath().'?limit='.$this->normalizePartsPageLimit($limit).'&page='.max(1, $page);
    }

    private function normalizePartsPageLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_PARTS_PAGE_LIMIT));
    }

    private function authFields(): array
    {
        $credentials = $this->credentials();

        return [
            'username' => (string) ($credentials['username'] ?? ''),
            'password' => (string) ($credentials['password'] ?? ''),
            'user_token' => (string) ($credentials['user_token'] ?? ''),
        ];
    }

    protected function extractOffers(array $payload): array
    {
        $rows = $payload['data'] ?? $payload['parts'] ?? [];
        if (! is_array($rows)) return [];
        return array_values(array_map(function ($row) {
            $category = is_array($row['category'] ?? null) ? $row['category'] : [];

            return [
            'external_offer_id' => (string) ($row['id'] ?? $row['part_id'] ?? $row['ovoko_part_id'] ?? $row['external_id'] ?? ''),
            'title' => $row['name'] ?? $row['title'] ?? null,
            'sku' => $row['sku'] ?? $row['code'] ?? null,
            'price' => $row['price'] ?? $row['sell_price'] ?? null,
            'currency' => $row['currency'] ?? $row['price_currency'] ?? null,
            'original_price' => $row['original_price'] ?? null,
            'original_currency' => $row['original_currency'] ?? null,
            'quantity' => $row['quantity'] ?? $row['stock'] ?? $row['qty'] ?? null,
            'status' => $row['status'] ?? null,
            'url' => $row['url'] ?? null,
            'ovoko_category_id' => $row['category_id'] ?? $row['categoryId'] ?? $category['id'] ?? $category['category_id'] ?? $category['categoryId'] ?? null,
            'ovoko_category_name' => $row['category_name'] ?? $row['categoryName'] ?? $category['name'] ?? $category['category_name'] ?? null,
            'ovoko_category_path' => $row['category_path'] ?? $row['categoryPath'] ?? $category['path'] ?? $category['category_path'] ?? null,
            'raw_category_fields' => $this->rawCategoryFields($row),
        ];
        }, array_filter($rows, 'is_array')));
    }

    private function rawCategoryFields(array $row): array
    {
        return array_filter([
            'category_id' => $row['category_id'] ?? null,
            'categoryId' => $row['categoryId'] ?? null,
            'category_name' => $row['category_name'] ?? null,
            'categoryName' => $row['categoryName'] ?? null,
            'category_path' => $row['category_path'] ?? null,
            'categoryPath' => $row['categoryPath'] ?? null,
            'category' => $row['category'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}

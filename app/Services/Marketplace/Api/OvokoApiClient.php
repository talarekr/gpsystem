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

    public function fetchCategories(int $timeoutSeconds = 30): array
    {
        $endpoint = rtrim((string) $this->account?->api_base_url, '/').'/get/categories';
        $response = Http::asForm()->acceptJson()->timeout(max(1, $timeoutSeconds))->post($endpoint, $this->authFields());
        $json = $response->json();
        $payload = is_array($json) ? $json : [];
        $statusCode = $payload['status_code'] ?? null;
        $apiOk = $response->successful() && ($statusCode === 'R200' || $statusCode === 200);
        $rows = $payload['list'] ?? $payload['data'] ?? $payload['categories'] ?? [];

        return [
            'http_status' => $response->status(),
            'api_status_code' => $statusCode,
            'api_ok' => $apiOk,
            'endpoint_used' => $endpoint,
            'categories' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
            'error' => $payload['msg'] ?? $payload['message'] ?? null,
            'response_top_level_keys' => array_values(array_slice(array_keys($payload), 0, 30)),
        ];
    }

    public function fetchPartsPage(int $page, int $limit, int $timeoutSeconds = 30): array
    {
        $page = max(1, $page);
        $limit = $this->normalizePartsPageLimit($limit);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(max(1, $timeoutSeconds))
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

    public function normalizeOffer(array $row): array
    {
        return $this->extractOffers(['data' => [$row]])[0] ?? [];
    }

    public function fetchPartRawById(string $id): array
    {
        $mismatches = [];
        foreach ($this->comparePartDetailEndpoints($id) as $candidate) {
            if (($candidate['api_ok'] ?? false) && ($candidate['matched_requested_id'] ?? false) && is_array($candidate['raw'] ?? null)) {
                return [
                    'http_status' => $candidate['http_status'] ?? null,
                    'api_status_code' => $candidate['api_status_code'] ?? null,
                    'api_ok' => true,
                    'endpoint_used' => $candidate['endpoint'],
                    'raw' => $candidate['raw'],
                    'normalized' => $this->normalizeOffer($candidate['raw']),
                    'response_top_level_keys' => $candidate['top_level_keys'] ?? [],
                    'error' => $candidate['error'] ?? null,
                    'matched_requested_id' => true,
                    'returned_raw_id' => $candidate['returned_raw_id'] ?? null,
                    'returned_external_id' => $candidate['returned_external_id'] ?? null,
                ];
            }
            if (($candidate['api_ok'] ?? false) && is_array($candidate['raw'] ?? null)) {
                $mismatches[] = [
                    'endpoint' => $candidate['endpoint'] ?? null,
                    'returned_raw_id' => $candidate['returned_raw_id'] ?? null,
                    'returned_external_id' => $candidate['returned_external_id'] ?? null,
                    'returned_name' => $candidate['returned_name'] ?? null,
                ];
            }
        }

        return ['api_ok' => false, 'error' => $mismatches === [] ? 'part_detail_not_found_on_known_read_only_endpoints' : 'detail_id_mismatch', 'matched_requested_id' => false, 'mismatches' => $mismatches];
    }

    public function comparePartDetailEndpoints(string $id): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $encodedId = rawurlencode($id);
        $variants = [
            ['endpoint' => $base.'/v2/get/parts/'.$encodedId, 'fields' => []],
            ['endpoint' => $base.'/v2/get/part/'.$encodedId, 'fields' => []],
            ['endpoint' => $base.'/get/part/'.$encodedId, 'fields' => []],
            ['endpoint' => $base.'/v2/get/parts', 'fields' => ['id' => $id]],
            ['endpoint' => $base.'/v2/get/parts', 'fields' => ['part_id' => $id]],
            ['endpoint' => $base.'/v2/get/parts', 'fields' => ['ids' => [$id]]],
        ];

        return array_map(fn (array $variant): array => $this->probePartDetailEndpoint($variant['endpoint'], $variant['fields'], $id), $variants);
    }

    private function probePartDetailEndpoint(string $endpoint, array $fields, string $requestedId): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post($endpoint, $this->authFields() + $fields);
            $json = $response->json();
            $payload = is_array($json) ? $json : [];
            $row = $this->extractSingleOfferRow($payload);
            $normalized = is_array($row) ? $this->normalizeOffer($row) : [];
            $rawId = is_array($row) ? $this->stringOrNull($row['id'] ?? $row['raw_id'] ?? null) : null;
            $externalId = is_array($row) ? $this->firstString($row, ['external_id', 'external_offer_id', 'part_id', 'ovoko_part_id', 'rrr_id', 'id']) : null;

            return [
                'endpoint' => $endpoint,
                'request_fields' => array_keys($fields),
                'http_status' => $response->status(),
                'api_status_code' => $payload['status_code'] ?? null,
                'api_ok' => $response->successful() && (($payload['status_code'] ?? null) === 'R200' || ($payload['status_code'] ?? null) === 200),
                'matched_requested_id' => $this->matchesRequestedId($requestedId, $row ?? null, $normalized),
                'returned_raw_id' => $rawId,
                'returned_external_id' => $externalId,
                'returned_name' => is_array($row) ? ($row['name'] ?? $row['title'] ?? null) : null,
                'returned_category_id' => is_array($row) ? ($row['category_id'] ?? $row['categoryId'] ?? $row['part_category_id'] ?? data_get($row, 'category.id') ?? null) : null,
                'returned_shop_url' => is_array($row) ? ($row['shop_url'] ?? $row['url'] ?? $row['link'] ?? null) : null,
                'top_level_keys' => array_values(array_slice(array_keys($payload), 0, 30)),
                'error' => $payload['msg'] ?? $payload['message'] ?? null,
                'raw' => is_array($row) ? $row : null,
            ];
        } catch (\Throwable $e) {
            return ['endpoint' => $endpoint, 'request_fields' => array_keys($fields), 'http_status' => null, 'api_status_code' => null, 'api_ok' => false, 'matched_requested_id' => false, 'returned_raw_id' => null, 'returned_external_id' => null, 'returned_name' => null, 'returned_category_id' => null, 'returned_shop_url' => null, 'top_level_keys' => [], 'error' => $e->getMessage(), 'raw' => null];
        }
    }

    private function matchesRequestedId(string $requestedId, ?array $row, array $normalized): bool
    {
        if (! is_array($row)) return false;
        foreach (['id', 'external_id', 'external_offer_id', 'part_id', 'ovoko_part_id', 'rrr_id'] as $key) {
            if ($this->stringOrNull($row[$key] ?? null) === $requestedId) return true;
        }
        return $this->stringOrNull($normalized['external_offer_id'] ?? null) === $requestedId;
    }

    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->stringOrNull($row[$key] ?? null);
            if ($value !== null) return $value;
        }
        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    protected function extractOffers(array $payload): array
    {
        $rows = $payload['data'] ?? $payload['parts'] ?? [];
        if (! is_array($rows)) return [];
        return array_values(array_map(function ($row) {
            $category = is_array($row['category'] ?? null) ? $row['category'] : [];

            return [
            'external_offer_id' => (string) ($row['id'] ?? $row['part_id'] ?? $row['ovoko_part_id'] ?? $row['external_id'] ?? ''),
            'raw_id' => $row['id'] ?? null,
            'external_id_raw' => $row['external_id'] ?? null,
            'part_id_raw' => $row['part_id'] ?? null,
            'ovoko_part_id_raw' => $row['ovoko_part_id'] ?? null,
            'rrr_id_raw' => $row['rrr_id'] ?? null,
            'title' => $row['name'] ?? $row['title'] ?? null,
            'sku' => $row['sku'] ?? $row['code'] ?? null,
            'price' => $row['price'] ?? $row['sell_price'] ?? null,
            'currency' => $row['currency'] ?? $row['price_currency'] ?? null,
            'original_price' => $row['original_price'] ?? null,
            'original_currency' => $row['original_currency'] ?? null,
            'quantity' => $row['quantity'] ?? $row['stock'] ?? $row['qty'] ?? null,
            'status' => $row['status'] ?? null,
            'url' => $row['shop_url'] ?? $row['url'] ?? $row['link'] ?? null,
            'ovoko_category_id' => $row['category_id'] ?? $row['categoryId'] ?? $row['part_category_id'] ?? $category['id'] ?? $category['category_id'] ?? $category['categoryId'] ?? null,
            'ovoko_category_name' => $row['category_name'] ?? $row['categoryName'] ?? $category['name'] ?? $category['category_name'] ?? $category['pl'] ?? $category['en'] ?? null,
            'ovoko_category_path' => $row['category_title_path'] ?? $row['category_path'] ?? $row['categoryPath'] ?? $category['path'] ?? $category['category_path'] ?? $category['category_title_path'] ?? null,
            'raw_category_fields' => $this->rawCategoryFields($row),
            'raw_top_level_keys' => array_values(array_slice(array_keys($row), 0, 80)),
        ];
        }, array_filter($rows, 'is_array')));
    }

    private function extractSingleOfferRow(array $payload): ?array
    {
        foreach (['data', 'part', 'item', 'offer'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value) && array_is_list($value)) {
                $first = $value[0] ?? null;
                if (is_array($first)) return $first;
            }
            if (is_array($value)) return $value;
        }

        return null;
    }

    private function rawCategoryFields(array $row): array
    {
        return array_filter([
            'category_id' => $row['category_id'] ?? null,
            'categoryId' => $row['categoryId'] ?? null,
            'part_category_id' => $row['part_category_id'] ?? null,
            'categoryIdRaw' => $row['categoryIdRaw'] ?? null,
            'category_title_path' => $row['category_title_path'] ?? null,
            'category_name' => $row['category_name'] ?? null,
            'categoryName' => $row['categoryName'] ?? null,
            'category_path' => $row['category_path'] ?? null,
            'categoryPath' => $row['categoryPath'] ?? null,
            'category' => $row['category'] ?? null,
            'part' => $row['part'] ?? null,
            'type' => $row['type'] ?? null,
            'group' => $row['group'] ?? null,
            'category_tree' => $row['category_tree'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}

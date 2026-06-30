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


    /**
     * Create a CRM part in Ovoko/RRR using the documented /crm/importPart form endpoint.
     * This method performs only stage-1 publish/import of a part; it does not import orders,
     * update stock, sync prices, ship orders, end listings, or schedule background work.
     */
    public function importPart(array $fields, int $timeoutSeconds = 30): array
    {
        $endpoint = rtrim((string) $this->account?->api_base_url, '/').'/crm/importPart';
        $form = $this->authFields() + $fields;
        $encodedForm = $this->encodeFormRepeatedKeys($form);

        $response = Http::withBody($encodedForm, 'application/x-www-form-urlencoded')
            ->acceptJson()
            ->timeout(max(1, $timeoutSeconds))
            ->post($endpoint);

        $json = $response->json();
        $payload = is_array($json) ? $json : [];
        $statusCode = $payload['status_code'] ?? null;
        $apiOk = $response->successful() && ($statusCode === 'R200' || $statusCode === 200);

        return [
            'http_status' => $response->status(),
            'api_status_code' => $statusCode,
            'api_ok' => $apiOk,
            'endpoint_used' => $endpoint,
            'part_id' => $payload['part_id'] ?? null,
            'shop_url' => $payload['shop_url'] ?? null,
            'message' => $payload['msg'] ?? $payload['message'] ?? null,
            'response_top_level_keys' => array_values(array_slice(array_keys($payload), 0, 30)),
            'payload' => $payload,
        ];
    }

    public function importPartFormDiagnostics(array $fields): array
    {
        $form = $this->authFields() + $fields;
        $encoded = $this->encodeFormRepeatedKeys($form, true);

        return [
            'ovoko_form_encoding' => 'application/x-www-form-urlencoded',
            'ovoko_photo_field_type' => get_debug_type($fields['photo'] ?? null),
            'ovoko_photos_field_encoding_shape' => is_array($fields['photos[]'] ?? null) ? 'repeated_photos_brackets' : get_debug_type($fields['photos[]'] ?? null),
            'ovoko_photos_repeated_keys_preview' => array_values(array_filter(explode('&', $encoded), fn (string $pair): bool => str_starts_with($pair, 'photos%5B%5D='))),
        ];
    }

    private function encodeFormRepeatedKeys(array $form, bool $maskSecrets = false): string
    {
        $pairs = [];
        foreach ($form as $key => $value) {
            $values = is_array($value) ? array_values($value) : [$value];
            foreach ($values as $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                $safeValue = $maskSecrets && in_array($key, ['username', 'password', 'user_token'], true) ? '***' : (string) $item;
                $pairs[] = rawurlencode((string) $key).'='.rawurlencode($safeValue);
            }
        }

        return implode('&', $pairs);
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

    public function fetchPartRawById(string $id, int $maxPages = 3): array
    {
        return $this->fetchPartRawByLookup($id, null, $maxPages);
    }

    public function fetchPartRawByLookup(string $id, ?string $externalId = null, int $maxPages = 3): array
    {
        $mismatches = [];
        $attempts = $this->comparePartDetailEndpoints($id, $externalId, $maxPages);
        foreach ($attempts as $candidate) {
            if (($candidate['api_ok'] ?? false) && ($candidate['matched_requested_id'] ?? false) && is_array($candidate['raw'] ?? null)) {
                return [
                    'http_status' => $candidate['http_status'] ?? null,
                    'api_status_code' => $candidate['api_status_code'] ?? null,
                    'api_ok' => true,
                    'endpoint_used' => $candidate['endpoint'],
                    'raw' => $candidate['raw'],
                    'normalized' => $this->normalizeOffer($candidate['raw']),
                    'response_top_level_keys' => $candidate['top_level_keys'] ?? [],
                    'request_fields' => $candidate['request_fields'] ?? [],
                    'attempts' => $this->summarizePartDetailAttempts($attempts),
                    'error' => $candidate['error'] ?? null,
                    'matched_requested_id' => true,
                    'returned_raw_id' => $candidate['returned_raw_id'] ?? null,
                    'returned_external_id' => $candidate['returned_external_id'] ?? null,
                    'returned_candidates_count' => $candidate['returned_candidates_count'] ?? 0,
                    'matched_candidate_index' => $candidate['matched_candidate_index'] ?? null,
                    'matched_candidate_id' => $candidate['returned_raw_id'] ?? null,
                    'matched_candidate_external_id' => $candidate['returned_external_id'] ?? null,
                    'matched_candidate_shop_url' => $candidate['returned_shop_url'] ?? null,
                    'mismatch_sample_ids' => $candidate['mismatch_sample_ids'] ?? [],
                    'returned_pagination' => $candidate['returned_pagination'] ?? null,
                    'returned_pagination_count' => $candidate['returned_pagination_count'] ?? null,
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

        $best = collect($attempts)->sortByDesc(fn (array $attempt): int => (int) ($attempt['returned_candidates_count'] ?? 0))->first() ?? [];

        return [
            'api_ok' => false,
            'error' => $mismatches === [] ? 'part_detail_not_found_on_known_read_only_endpoints_csv_export_required' : 'detail_id_mismatch',
            'matched_requested_id' => false,
            'mismatches' => $mismatches,
            'attempts' => $this->summarizePartDetailAttempts($attempts),
            'returned_candidates_count' => $best['returned_candidates_count'] ?? 0,
            'matched_candidate_index' => null,
            'matched_candidate_id' => null,
            'matched_candidate_external_id' => null,
            'matched_candidate_shop_url' => null,
            'mismatch_sample_ids' => $best['mismatch_sample_ids'] ?? [],
            'returned_pagination' => $best['returned_pagination'] ?? null,
            'returned_pagination_count' => $best['returned_pagination_count'] ?? null,
        ];
    }

    private function summarizePartDetailAttempts(array $attempts): array
    {
        return array_map(fn (array $attempt): array => [
            'method' => $attempt['method'] ?? 'GET',
            'endpoint' => $attempt['endpoint'] ?? null,
            'query_params' => $attempt['query_params'] ?? [],
            'pagination_scan' => $attempt['pagination_scan'] ?? false,
            'request_fields' => $attempt['request_fields'] ?? [],
            'http_status' => $attempt['http_status'] ?? null,
            'api_status_code' => $attempt['api_status_code'] ?? null,
            'api_ok' => $attempt['api_ok'] ?? false,
            'matched_requested_id' => $attempt['matched_requested_id'] ?? false,
            'returned_raw_id' => $attempt['returned_raw_id'] ?? null,
            'returned_external_id' => $attempt['returned_external_id'] ?? null,
            'returned_candidates_count' => $attempt['returned_candidates_count'] ?? 0,
            'matched_candidate_index' => $attempt['matched_candidate_index'] ?? null,
            'mismatch_sample_ids' => $attempt['mismatch_sample_ids'] ?? [],
            'returned_pagination' => $attempt['returned_pagination'] ?? null,
            'returned_pagination_count' => $attempt['returned_pagination_count'] ?? null,
            'returned_shop_url_present' => $this->stringOrNull($attempt['returned_shop_url'] ?? null) !== null,
            'returned_url_fields' => $attempt['returned_url_fields'] ?? [],
            'top_level_keys' => $attempt['top_level_keys'] ?? [],
            'error' => $attempt['error'] ?? null,
        ], $attempts);
    }

    public function comparePartDetailEndpoints(string $id, ?string $externalId = null, int $maxPages = 3): array
    {
        $base = rtrim((string) $this->account?->api_base_url, '/');
        $encodedId = rawurlencode($id);
        $variants = [
            ['endpoint' => $base.'/v2/get/parts', 'fields' => ['user_code' => $externalId], 'skip' => $externalId === null || $externalId === ''],
            ['endpoint' => $base.'/v2/get/parts', 'fields' => ['external_id' => $externalId], 'skip' => $externalId === null || $externalId === ''],
            ['endpoint' => $base.'/v2/get/parts', 'fields' => ['id' => $id]],
            ['endpoint' => $base.'/v2/get/parts', 'fields' => ['ids[]' => [$id]]],
            ['endpoint' => $base.'/v2/get/parts', 'fields' => ['part_ids[]' => [$id]]],
            ['endpoint' => $base.'/v2/get/part', 'fields' => ['id' => $id]],
            ['endpoint' => $base.'/v2/part/'.$encodedId, 'fields' => []],
            ['endpoint' => $base.'/v2/get/part/'.$encodedId, 'fields' => []],
            ['endpoint' => $base.'/get/part/'.$encodedId, 'fields' => []],
        ];

        $attempts = [];
        foreach ($variants as $variant) {
            if (($variant['skip'] ?? false) === true) continue;
            $attempts[] = $this->probePartDetailEndpoint($variant['endpoint'], $variant['fields'], $id, $externalId);
        }

        $maxPages = max(1, min(50, $maxPages));
        for ($page = 1; $page <= $maxPages; $page++) {
            $attempt = $this->probePartDetailEndpoint($base.'/v2/get/parts', ['limit' => self::MAX_PARTS_PAGE_LIMIT, 'page' => $page], $id, $externalId);
            $attempt['pagination_scan'] = true;
            $attempts[] = $attempt;
            if (($attempt['matched_requested_id'] ?? false) === true) break;
        }

        return $attempts;
    }

    private function probePartDetailEndpoint(string $endpoint, array $fields, string $requestedId, ?string $requestedExternalId): array
    {
        try {
            $queryFields = $this->authFields() + $fields;
            $queryString = $this->encodeFormRepeatedKeys($queryFields);
            $url = $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$queryString;
            $response = Http::acceptJson()->timeout(30)->get($url);
            $json = $response->json();
            $payload = is_array($json) ? $json : [];
            $rows = $this->extractOfferRows($payload);
            [$row, $matchedIndex] = $this->findMatchingOfferRow($rows, $requestedId, $requestedExternalId);
            $diagnosticRow = is_array($row) ? $row : ($rows[0] ?? null);
            $normalized = is_array($row) ? $this->normalizeOffer($row) : [];
            $rawId = is_array($diagnosticRow) ? $this->stringOrNull($diagnosticRow['id'] ?? $diagnosticRow['raw_id'] ?? null) : null;
            $externalId = is_array($diagnosticRow) ? $this->firstString($diagnosticRow, ['external_id', 'external_offer_id', 'part_id', 'ovoko_part_id', 'rrr_id', 'id']) : null;
            $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : null;

            return [
                'method' => 'GET',
                'endpoint' => $endpoint,
                'query_params' => $this->sanitizeQueryFields($fields),
                'request_fields' => array_keys($fields),
                'http_status' => $response->status(),
                'api_status_code' => $payload['status_code'] ?? null,
                'api_ok' => $response->successful() && (($payload['status_code'] ?? null) === 'R200' || ($payload['status_code'] ?? null) === 200),
                'matched_requested_id' => is_array($row),
                'returned_raw_id' => $rawId,
                'returned_external_id' => $externalId,
                'returned_name' => is_array($diagnosticRow) ? ($diagnosticRow['name'] ?? $diagnosticRow['title'] ?? null) : null,
                'returned_category_id' => is_array($diagnosticRow) ? ($diagnosticRow['category_id'] ?? $diagnosticRow['categoryId'] ?? $diagnosticRow['part_category_id'] ?? data_get($diagnosticRow, 'category.id') ?? null) : null,
                'returned_shop_url' => is_array($diagnosticRow) ? ($diagnosticRow['shop_url'] ?? $diagnosticRow['url'] ?? $diagnosticRow['link'] ?? $diagnosticRow['public_url'] ?? $diagnosticRow['marketplace_url'] ?? null) : null,
                'returned_url_fields' => is_array($diagnosticRow) ? $this->extractUrlFields($diagnosticRow) : [],
                'returned_candidates_count' => count($rows),
                'matched_candidate_index' => $matchedIndex,
                'mismatch_sample_ids' => array_values(array_slice(array_map(fn (array $candidate): ?string => $this->firstString($candidate, ['id', 'external_id', 'part_id', 'ovoko_part_id', 'rrr_id']), $rows), 0, 5)),
                'returned_pagination' => $pagination,
                'returned_pagination_count' => is_numeric($pagination['total_count'] ?? null) ? (int) $pagination['total_count'] : (is_numeric($pagination['total'] ?? null) ? (int) $pagination['total'] : null),
                'top_level_keys' => array_values(array_slice(array_keys($payload), 0, 30)),
                'error' => $payload['msg'] ?? $payload['message'] ?? null,
                'raw' => is_array($row) ? $row : null,
            ];
        } catch (\Throwable $e) {
            return ['method' => 'GET', 'endpoint' => $endpoint, 'query_params' => $this->sanitizeQueryFields($fields), 'request_fields' => array_keys($fields), 'http_status' => null, 'api_status_code' => null, 'api_ok' => false, 'matched_requested_id' => false, 'returned_raw_id' => null, 'returned_external_id' => null, 'returned_name' => null, 'returned_category_id' => null, 'returned_shop_url' => null, 'returned_candidates_count' => 0, 'matched_candidate_index' => null, 'mismatch_sample_ids' => [], 'returned_pagination' => null, 'returned_pagination_count' => null, 'top_level_keys' => [], 'error' => $e->getMessage(), 'raw' => null];
        }
    }

    private function sanitizeQueryFields(array $fields): array
    {
        return $fields;
    }

    private function extractUrlFields(array $row): array
    {
        return array_filter([
            'url' => $this->stringOrNull($row['url'] ?? null),
            'shop_url' => $this->stringOrNull($row['shop_url'] ?? null),
            'link' => $this->stringOrNull($row['link'] ?? null),
            'public_url' => $this->stringOrNull($row['public_url'] ?? null),
            'marketplace_url' => $this->stringOrNull($row['marketplace_url'] ?? null),
        ], fn ($value) => $value !== null);
    }

    private function matchesRequestedId(string $requestedId, ?string $requestedExternalId, ?array $row, array $normalized): bool
    {
        if (! is_array($row)) return false;
        foreach (['id', 'external_id', 'external_offer_id', 'part_id', 'ovoko_part_id', 'rrr_id'] as $key) {
            if ($this->stringOrNull($row[$key] ?? null) === $requestedId) return true;
            if ($requestedExternalId !== null && $this->stringOrNull($row[$key] ?? null) === $requestedExternalId) return true;
        }
        $normalizedId = $this->stringOrNull($normalized['external_offer_id'] ?? null);

        return $normalizedId === $requestedId || ($requestedExternalId !== null && $normalizedId === $requestedExternalId);
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
            'url' => $row['shop_url'] ?? $row['url'] ?? $row['link'] ?? $row['public_url'] ?? $row['marketplace_url'] ?? null,
            'ovoko_category_id' => $row['category_id'] ?? $row['categoryId'] ?? $row['part_category_id'] ?? $category['id'] ?? $category['category_id'] ?? $category['categoryId'] ?? null,
            'ovoko_category_name' => $row['category_name'] ?? $row['categoryName'] ?? $category['name'] ?? $category['category_name'] ?? $category['pl'] ?? $category['en'] ?? null,
            'ovoko_category_path' => $row['category_title_path'] ?? $row['category_path'] ?? $row['categoryPath'] ?? $category['path'] ?? $category['category_path'] ?? $category['category_title_path'] ?? null,
            'raw_category_fields' => $this->rawCategoryFields($row),
            'raw_top_level_keys' => array_values(array_slice(array_keys($row), 0, 80)),
        ];
        }, array_filter($rows, 'is_array')));
    }

    private function extractOfferRows(array $payload): array
    {
        foreach (['data', 'part', 'item', 'offer'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
            if (is_array($value)) return [$value];
        }

        return [];
    }

    private function findMatchingOfferRow(array $rows, string $requestedId, ?string $requestedExternalId): array
    {
        foreach ($rows as $index => $row) {
            if ($this->rowHasOvokoId($row, $requestedId)) {
                return [$row, $index];
            }
        }

        if ($requestedExternalId !== null && $requestedExternalId !== '') {
            foreach ($rows as $index => $row) {
                $normalized = $this->normalizeOffer($row);
                if ($this->rowHasExternalId($row, $normalized, $requestedExternalId) && ! $this->rowHasDifferentOvokoId($row, $requestedId)) {
                    return [$row, $index];
                }
            }
        }

        return [null, null];
    }

    private function rowHasOvokoId(array $row, string $requestedId): bool
    {
        foreach (['id', 'part_id', 'ovoko_part_id', 'rrr_id'] as $key) {
            if ($this->stringOrNull($row[$key] ?? null) === $requestedId) return true;
        }
        return false;
    }

    private function rowHasDifferentOvokoId(array $row, string $requestedId): bool
    {
        foreach (['id', 'part_id', 'ovoko_part_id', 'rrr_id'] as $key) {
            $value = $this->stringOrNull($row[$key] ?? null);
            if ($value !== null && $value !== $requestedId) return true;
        }
        return false;
    }

    private function rowHasExternalId(array $row, array $normalized, string $requestedExternalId): bool
    {
        foreach (['external_id', 'external_offer_id', 'visible_code', 'sku', 'code'] as $key) {
            if ($this->stringOrNull($row[$key] ?? null) === $requestedExternalId) return true;
        }

        return $this->stringOrNull($normalized['sku'] ?? null) === $requestedExternalId;
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

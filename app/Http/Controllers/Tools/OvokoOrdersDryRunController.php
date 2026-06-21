<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OvokoOrdersDryRunController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const ENDPOINT_PATH = '/v2/get/orders';
    private const MAX_ORDERS = 500;
    private const SAMPLE_LIMIT = 10;
    private const INSPECT_LIMIT = 3;

    private const PART_ID_PATHS = [
        'id',
        'part_id',
        'rrr_part_id',
        'external_id',
        'part.id',
        'part.part_id',
        'part.rrr_part_id',
        'item.id',
        'item.part_id',
        'product.id',
        'product.part_id',
        'part.code',
        'part.external_id',
        'listing_id',
        'listing.id',
        'product.listing_id',
        'id_bridge',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $dates = $this->validatedDates($request);
        if ($dates instanceof JsonResponse) {
            return $dates;
        }

        [$from, $to] = $dates;
        $account = Schema::hasTable('marketplace_accounts')
            ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first()
            : null;

        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $credentialsConfigured = filled($credentials['username'] ?? null)
            && filled($credentials['password'] ?? null)
            && filled($credentials['user_token'] ?? null);

        $baseResponse = [
            'ok' => false,
            'dry_run' => true,
            'from' => $from,
            'to' => $to,
            'api_enabled' => (bool) ($account?->api_enabled ?? false),
            'api_mode' => $account?->api_mode,
            'connection_ok' => false,
            'http_status' => null,
            'api_status_code' => null,
            'api_status_message' => null,
            'orders_count' => 0,
            'order_items_count' => 0,
            'matched_items_count' => 0,
            'unmatched_items_count' => 0,
            'would_mark_sold_count' => 0,
            'would_set_quantity_zero_count' => 0,
            'samples_orders' => [],
            'samples_matched_items' => [],
            'samples_unmatched_items' => [],
            'samples_would_update_parts' => [],
            'endpoint_used' => $this->endpointUsed((string) ($account?->api_base_url ?? ''), $from, $to),
            'request_method' => 'POST',
            'request_format' => 'form-data',
            'pagination' => ['supported' => false, 'max_orders' => self::MAX_ORDERS],
            'truncated' => false,
        ];

        if ($account === null) {
            return response()->json($baseResponse + ['api_status_message' => 'Marketplace account ovoko_main was not found.'], 404);
        }

        $validationMessage = $this->validateConfiguration($account, $credentialsConfigured);
        if ($validationMessage !== null) {
            return response()->json(array_merge($baseResponse, ['api_status_message' => $validationMessage]), 422);
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post($baseResponse['endpoint_used'], [
                'username' => (string) $credentials['username'],
                'password' => (string) $credentials['password'],
                'user_token' => (string) $credentials['user_token'],
            ]);

            $payload = is_array($response->json()) ? $response->json() : [];
            $apiStatusCode = $payload['status_code'] ?? null;
            $apiStatusMessage = $payload['msg'] ?? ($payload['message'] ?? null);
            $connectionOk = $response->successful() && $apiStatusCode === 'R200';

            if (! $connectionOk) {
                return response()->json(array_merge($baseResponse, [
                    'http_status' => $response->status(),
                    'api_status_code' => $apiStatusCode,
                    'api_status_message' => $this->safeFailureMessage($apiStatusCode, $apiStatusMessage, $response->status()),
                ]), 502);
            }

            $orders = $this->ordersFromPayload($payload);
            $truncated = count($orders) > self::MAX_ORDERS;
            $orders = array_slice($orders, 0, self::MAX_ORDERS);
            $summary = $this->summarizeOrders($orders);

            Log::info('Ovoko orders dry-run completed.', Arr::only($summary, [
                'orders_count', 'order_items_count', 'matched_items_count', 'unmatched_items_count', 'would_mark_sold_count', 'would_set_quantity_zero_count',
            ]) + ['from' => $from, 'to' => $to, 'truncated' => $truncated]);

            return response()->json(array_merge($baseResponse, $summary, [
                'ok' => true,
                'connection_ok' => true,
                'http_status' => $response->status(),
                'api_status_code' => $apiStatusCode,
                'api_status_message' => $apiStatusMessage,
                'pagination' => ['supported' => false, 'max_orders' => self::MAX_ORDERS, 'received_orders' => count($this->ordersFromPayload($payload))],
                'truncated' => $truncated,
            ]));
        } catch (ConnectionException) {
            return response()->json(array_merge($baseResponse, ['api_status_message' => 'Ovoko/RRR orders request timed out or failed.']), 502);
        } catch (Throwable) {
            return response()->json(array_merge($baseResponse, ['api_status_message' => 'Ovoko/RRR orders dry-run failed unexpectedly.']), 500);
        }
    }

    public function inspect(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $dates = $this->validatedDates($request);
        if ($dates instanceof JsonResponse) {
            return $dates;
        }

        [$from, $to] = $dates;
        $limit = max(1, min((int) $request->query('limit', self::INSPECT_LIMIT), 10));
        $account = Schema::hasTable('marketplace_accounts')
            ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first()
            : null;
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $credentialsConfigured = filled($credentials['username'] ?? null)
            && filled($credentials['password'] ?? null)
            && filled($credentials['user_token'] ?? null);

        $baseResponse = [
            'ok' => false,
            'dry_run' => true,
            'from' => $from,
            'to' => $to,
            'limit' => $limit,
            'orders_count' => 0,
            'endpoint_used' => $this->endpointUsed((string) ($account?->api_base_url ?? ''), $from, $to),
            'request_method' => 'POST',
            'request_format' => 'form-data',
            'credentials_returned' => false,
        ];

        if ($account === null) {
            return response()->json($baseResponse + ['api_status_message' => 'Marketplace account ovoko_main was not found.'], 404);
        }

        $validationMessage = $this->validateConfiguration($account, $credentialsConfigured);
        if ($validationMessage !== null) {
            return response()->json(array_merge($baseResponse, ['api_status_message' => $validationMessage]), 422);
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post($baseResponse['endpoint_used'], [
                'username' => (string) $credentials['username'],
                'password' => (string) $credentials['password'],
                'user_token' => (string) $credentials['user_token'],
            ]);

            $payload = is_array($response->json()) ? $response->json() : [];
            $apiStatusCode = $payload['status_code'] ?? null;
            $apiStatusMessage = $payload['msg'] ?? ($payload['message'] ?? null);
            $connectionOk = $response->successful() && $apiStatusCode === 'R200';

            if (! $connectionOk) {
                return response()->json(array_merge($baseResponse, [
                    'http_status' => $response->status(),
                    'api_status_code' => $apiStatusCode,
                    'api_status_message' => $this->safeFailureMessage($apiStatusCode, $apiStatusMessage, $response->status()),
                ]), 502);
            }

            $orders = $this->ordersFromPayload($payload);

            return response()->json(array_merge($baseResponse, $this->inspectOrdersStructure($orders, $limit), [
                'ok' => true,
                'connection_ok' => true,
                'http_status' => $response->status(),
                'api_status_code' => $apiStatusCode,
                'api_status_message' => $apiStatusMessage,
            ]));
        } catch (ConnectionException) {
            return response()->json(array_merge($baseResponse, ['api_status_message' => 'Ovoko/RRR orders structure request timed out or failed.']), 502);
        } catch (Throwable) {
            return response()->json(array_merge($baseResponse, ['api_status_message' => 'Ovoko/RRR orders structure inspection failed unexpectedly.']), 500);
        }
    }

    private function validatedDates(Request $request): array|JsonResponse
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', now()->toDateString());

        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return response()->json(['ok' => false, 'dry_run' => true, 'error_message' => "Invalid {$field} date. Expected Y-m-d."], 422);
            }
        }

        if (CarbonImmutable::parse($from)->gt(CarbonImmutable::parse($to))) {
            return response()->json(['ok' => false, 'dry_run' => true, 'error_message' => 'The from date must be before or equal to the to date.'], 422);
        }

        return [$from, $to];
    }

    private function validateConfiguration(MarketplaceAccount $account, bool $credentialsConfigured): ?string
    {
        if (! $account->api_enabled) return 'Ovoko API is not enabled for ovoko_main.';
        if (blank($account->api_base_url)) return 'Ovoko API base URL is missing.';
        if ($account->api_mode !== 'dry_run') return 'Ovoko orders dry-run is allowed only in dry_run mode.';
        if (! $credentialsConfigured) return 'Ovoko API credentials are not fully configured.';
        return null;
    }

    private function endpointUsed(string $baseUrl, string $from, string $to): string
    {
        return rtrim($baseUrl, '/').self::ENDPOINT_PATH.'/'.$from.'/'.$to;
    }

    private function ordersFromPayload(array $payload): array
    {
        $orders = $payload['list'] ?? $payload['data'] ?? $payload['orders'] ?? [];
        return is_array($orders) ? array_values($orders) : [];
    }

    private function summarizeOrders(array $orders): array
    {
        $items = [];
        foreach ($orders as $order) {
            foreach ($this->itemsFromOrder((array) $order) as $item) {
                $items[] = ['order' => (array) $order, 'item' => $item, 'ovoko_part_id' => $this->extractPartId($item)];
            }
        }

        $partIds = collect($items)->pluck('ovoko_part_id')->filter()->unique()->values();
        $listings = MarketplaceListing::query()->with('part:id,sku,name,quantity,status')
            ->where('marketplace', 'ovoko')->whereIn('external_offer_id', $partIds)->get()->keyBy('external_offer_id');

        $matched = [];
        $unmatched = [];
        $parts = [];
        foreach ($items as $row) {
            $listing = $row['ovoko_part_id'] ? $listings->get((string) $row['ovoko_part_id']) : null;
            $sample = $this->itemSample($row['order'], $row['item'], $row['ovoko_part_id'], $listing);
            if ($listing) {
                $matched[] = $sample;
                $parts[$listing->part_id] = $sample;
            } else {
                $unmatched[] = $sample;
            }
        }

        return [
            'orders_count' => count($orders),
            'order_items_count' => count($items),
            'matched_items_count' => count($matched),
            'unmatched_items_count' => count($unmatched),
            'would_mark_sold_count' => count($parts),
            'would_set_quantity_zero_count' => count($parts),
            'samples_orders' => array_slice(array_map(fn ($order) => $this->orderSample((array) $order), $orders), 0, self::SAMPLE_LIMIT),
            'samples_matched_items' => array_slice($matched, 0, self::SAMPLE_LIMIT),
            'samples_unmatched_items' => array_slice($unmatched, 0, self::SAMPLE_LIMIT),
            'samples_would_update_parts' => array_slice(array_values($parts), 0, self::SAMPLE_LIMIT),
        ];
    }

    private function itemsFromOrder(array $order): array
    {
        $items = $order['item_list'] ?? $order['items'] ?? $order['parts'] ?? [];
        return is_array($items) ? array_map(fn ($item) => (array) $item, array_values($items)) : [];
    }

    private function extractPartId(array $item): ?string
    {
        foreach (self::PART_ID_PATHS as $path) {
            $value = data_get($item, $path);
            if ($this->isUsableIdentifier($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function orderSample(array $order): array
    {
        return [
            'order_id' => $order['order_id'] ?? $order['id'] ?? null,
            'external_id' => $order['external_id'] ?? null,
            'status' => $order['status'] ?? null,
            'created_at' => $order['created_at'] ?? null,
            'date' => $order['order_date'] ?? $order['date'] ?? null,
            'buyer_country' => $order['client_address_country'] ?? $order['buyer_country'] ?? null,
            'total' => $order['total_price'] ?? $order['total'] ?? null,
            'currency' => $order['currency'] ?? null,
        ];
    }

    private function itemSample(array $order, array $item, ?string $ovokoPartId, ?MarketplaceListing $listing): array
    {
        $part = $listing?->part;
        return [
            'order_id' => $order['order_id'] ?? $order['id'] ?? null,
            'ovoko_part_id' => $ovokoPartId,
            'laravel_part_id' => $listing?->part_id,
            'sku' => $part?->sku ?? $listing?->sku ?? ($item['sku'] ?? null),
            'title' => $part?->name ?? $listing?->title ?? ($item['name'] ?? null),
            'current_quantity' => $part?->quantity ?? $listing?->quantity,
            'current_status' => $part?->status ?? $listing?->status,
            'action' => $listing ? 'would_mark_sold_and_set_quantity_0' : 'no_action_unmatched',
        ];
    }

    private function inspectOrdersStructure(array $orders, int $limit): array
    {
        $sampleOrders = array_slice(array_map(fn ($order) => (array) $order, $orders), 0, $limit);
        $sampleOrder = $sampleOrders[0] ?? [];
        $sampleItems = [];
        foreach ($sampleOrders as $order) {
            foreach ($this->itemsFromOrder($order) as $item) {
                $sampleItems[] = $item;
                if (count($sampleItems) >= $limit) {
                    break 2;
                }
            }
        }
        $sampleItem = $sampleItems[0] ?? [];
        $paths = [];
        foreach ($sampleItems as $index => $item) {
            $this->collectSafePaths($item, "items.{$index}", $paths);
        }

        return [
            'orders_count' => count($orders),
            'sample_order_top_level_keys' => array_keys($sampleOrder),
            'sample_order_item_keys' => array_keys($sampleItem),
            'sample_order_item_safe' => $this->safeTechnicalSample($sampleItem),
            'candidate_part_id_fields' => $this->candidateFields($sampleItems, ['id', 'part_id', 'rrr_part_id', 'part.id', 'part.part_id', 'item.id', 'item.part_id', 'product.id', 'product.part_id', 'part.code', 'part.external_id', 'id_bridge']),
            'candidate_listing_id_fields' => $this->candidateFields($sampleItems, ['listing_id', 'listing.id', 'offer_id', 'external_offer_id', 'product.listing_id']),
            'candidate_sku_fields' => $this->candidateFields($sampleItems, ['sku', 'oem', 'oem_code', 'part_number', 'part.sku', 'product.sku', 'code']),
            'raw_paths_with_id_like_keys' => $this->filterPaths($paths, '/(^|[._-])id($|[._-])/i'),
            'raw_paths_with_part_like_keys' => $this->filterPaths($paths, '/part/i'),
            'raw_paths_with_listing_like_keys' => $this->filterPaths($paths, '/listing|offer/i'),
            'raw_paths_with_sku_like_keys' => $this->filterPaths($paths, '/sku|oem|part_number|code/i'),
        ];
    }

    private function candidateFields(array $items, array $paths): array
    {
        $candidates = [];
        foreach ($paths as $path) {
            $values = collect($items)->map(fn ($item) => data_get($item, $path))->filter(fn ($value) => filled($value))->map(fn ($value) => is_scalar($value) ? (string) $value : gettype($value))->unique()->take(5)->values()->all();
            if ($values !== []) {
                $candidates[$path] = $values;
            }
        }
        return $candidates;
    }

    private function collectSafePaths(mixed $value, string $path, array &$paths): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $key = (string) $key;
                if ($this->isSensitiveKey($key)) continue;
                $this->collectSafePaths($nested, $path.'.'.$key, $paths);
            }
            return;
        }
        $paths[$path] = is_scalar($value) || $value === null ? $value : gettype($value);
    }

    private function filterPaths(array $paths, string $pattern): array
    {
        return collect($paths)
            ->filter(fn ($value, $path) => preg_match($pattern, (string) $path) === 1)
            ->map(fn ($value) => is_scalar($value) || $value === null ? $value : gettype($value))
            ->take(100)
            ->all();
    }

    private function safeTechnicalSample(array $item): array
    {
        $safe = [];
        $paths = [];
        $this->collectSafePaths($item, 'item', $paths);
        foreach ($paths as $path => $value) {
            $key = substr($path, 5);
            if ($key === '' || (! preg_match('/id|part|listing|offer|sku|oem|code|name|title|number|price|quantity|currency|status/i', $key))) {
                continue;
            }
            $safe[$key] = $value;
        }
        return $safe;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match('/password|token|secret|credential|buyer|client|customer|name_surname|email|phone|address|street|city|postcode|zip|company/i', $key) === 1
            && preg_match('/part|product|item|sku|oem|code|title|name$/i', $key) !== 1;
    }

    private function isUsableIdentifier(mixed $value): bool
    {
        if (! is_scalar($value) || ! filled($value)) return false;
        return trim((string) $value) !== '0';
    }

    private function safeFailureMessage(mixed $apiStatusCode, mixed $apiStatusMessage, int $httpStatus): string
    {
        $message = filled($apiStatusMessage) ? (string) $apiStatusMessage : 'Ovoko/RRR API returned a non-success status.';
        return "HTTP {$httpStatus}; API status ".($apiStatusCode ?: 'missing').'; '.$message;
    }
}

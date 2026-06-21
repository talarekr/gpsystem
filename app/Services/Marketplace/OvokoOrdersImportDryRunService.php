<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OvokoOrdersImportDryRunService
{
    public const TOKEN = 'gps_images_import_2026';
    private const ENDPOINT_PATH = '/v2/get/orders';
    private const MAX_ORDERS = 500;
    private const SAMPLE_LIMIT = 10;

    private const EXPORT_COLUMNS = [
        'ovoko_order_id',
        'order_date',
        'buyer_country',
        'ovoko_part_id',
        'item_name',
        'unit_price',
        'currency',
        'order_total_seller_amount',
        'order_total_seller_currency',
        'match_status',
        'reason',
        'laravel_part_id',
        'sku',
        'notes',
        'manual_action',
    ];

    public function run(string $from, ?string $to = null): array
    {
        $to ??= now()->toDateString();

        $account = Schema::hasTable('marketplace_accounts')
            ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first()
            : null;

        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $credentialsConfigured = filled($credentials['username'] ?? null)
            && filled($credentials['password'] ?? null)
            && filled($credentials['user_token'] ?? null);

        $base = [
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
            'existing_orders_count' => 0,
            'would_create_orders_count' => 0,
            'would_skip_existing_orders_count' => 0,
            'would_create_order_items_count' => 0,
            'would_create_unresolved_order_items_count' => 0,
            'would_mark_sold_count' => 0,
            'would_set_quantity_zero_count' => 0,
            'already_zero_items_count' => 0,
            'conflict_items_count' => 0,
            'unmatched_items_count' => 0,
            'samples_would_create_orders' => [],
            'samples_existing_orders' => [],
            'samples_would_create_order_items' => [],
            'samples_unresolved_items' => [],
            'samples_would_update_parts' => [],
            'endpoint_used' => $this->endpointUsed((string) ($account?->api_base_url ?? ''), $from, $to),
            'request_method' => 'POST',
            'request_format' => 'form-data',
            'pagination' => ['supported' => false, 'max_orders' => self::MAX_ORDERS],
            'truncated' => false,
        ];

        if ($account === null) {
            return $base + ['api_status_message' => 'Marketplace account ovoko_main was not found.', 'http_response_code' => 404];
        }

        $validationMessage = $this->validateConfiguration($account, $credentialsConfigured);
        if ($validationMessage !== null) {
            return array_merge($base, ['api_status_message' => $validationMessage, 'http_response_code' => 422]);
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post($base['endpoint_used'], [
                'username' => (string) $credentials['username'],
                'password' => (string) $credentials['password'],
                'user_token' => (string) $credentials['user_token'],
            ]);
        } catch (ConnectionException) {
            return array_merge($base, ['api_status_message' => 'Ovoko/RRR orders request timed out or failed.', 'http_response_code' => 502]);
        }

        $payload = is_array($response->json()) ? $response->json() : [];
        $apiStatusCode = $payload['status_code'] ?? null;
        $apiStatusMessage = $payload['msg'] ?? ($payload['message'] ?? null);
        $connectionOk = $response->successful() && $apiStatusCode === 'R200';

        if (! $connectionOk) {
            return array_merge($base, [
                'http_status' => $response->status(),
                'api_status_code' => $apiStatusCode,
                'api_status_message' => $this->safeFailureMessage($apiStatusCode, $apiStatusMessage, $response->status()),
                'http_response_code' => 502,
            ]);
        }

        $receivedOrders = $this->ordersFromPayload($payload);
        $truncated = count($receivedOrders) > self::MAX_ORDERS;
        $orders = array_slice($receivedOrders, 0, self::MAX_ORDERS);
        $summary = $this->summarizeImport($orders);

        Log::info('Ovoko orders import dry-run completed.', Arr::only($summary, [
            'orders_count', 'order_items_count', 'existing_orders_count', 'would_create_orders_count', 'would_create_order_items_count', 'would_mark_sold_count',
        ]) + ['from' => $from, 'to' => $to, 'truncated' => $truncated]);

        return array_merge($base, $summary, [
            'ok' => true,
            'connection_ok' => true,
            'http_status' => $response->status(),
            'api_status_code' => $apiStatusCode,
            'api_status_message' => $apiStatusMessage,
            'pagination' => ['supported' => false, 'max_orders' => self::MAX_ORDERS, 'received_orders' => count($receivedOrders)],
            'truncated' => $truncated,
            'http_response_code' => 200,
        ]);
    }


    public function exportUnmatched(string $from, ?string $to = null): array
    {
        $to ??= now()->toDateString();

        $result = $this->fetchOrders($from, $to);
        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        $receivedOrders = $result['orders'];
        $orders = array_slice($receivedOrders, 0, self::MAX_ORDERS);
        $summary = $this->summarizeImport($orders);
        $rows = $this->unmatchedExportRows($orders);
        $relativePath = 'exports/ovoko-orders-unmatched-'.$from.'-'.$to.'-'.now()->format('Ymd-His').'.csv';
        $absolutePath = Storage::disk('local')->path($relativePath);

        Storage::disk('local')->makeDirectory('exports');
        $handle = fopen($absolutePath, 'wb');
        fputcsv($handle, self::EXPORT_COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? '', self::EXPORT_COLUMNS));
        }
        fclose($handle);

        return [
            'ok' => true,
            'dry_run' => true,
            'from' => $from,
            'to' => $to,
            'orders_count' => $summary['orders_count'],
            'order_items_count' => $summary['order_items_count'],
            'unmatched_items_count' => $summary['unmatched_items_count'],
            'rows_count' => count($rows),
            'file' => $absolutePath,
            'download_url' => null,
            'generated_at' => now()->toISOString(),
            'http_response_code' => 200,
        ];
    }

    private function fetchOrders(string $from, string $to): array
    {
        $account = Schema::hasTable('marketplace_accounts')
            ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first()
            : null;

        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $credentialsConfigured = filled($credentials['username'] ?? null)
            && filled($credentials['password'] ?? null)
            && filled($credentials['user_token'] ?? null);

        if ($account === null) {
            return ['ok' => false, 'api_status_message' => 'Marketplace account ovoko_main was not found.', 'http_response_code' => 404];
        }

        $validationMessage = $this->validateConfiguration($account, $credentialsConfigured);
        if ($validationMessage !== null) {
            return ['ok' => false, 'api_status_message' => $validationMessage, 'http_response_code' => 422];
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post($this->endpointUsed((string) $account->api_base_url, $from, $to), [
                'username' => (string) $credentials['username'],
                'password' => (string) $credentials['password'],
                'user_token' => (string) $credentials['user_token'],
            ]);
        } catch (ConnectionException) {
            return ['ok' => false, 'api_status_message' => 'Ovoko/RRR orders request timed out or failed.', 'http_response_code' => 502];
        }

        $payload = is_array($response->json()) ? $response->json() : [];
        $apiStatusCode = $payload['status_code'] ?? null;
        $apiStatusMessage = $payload['msg'] ?? ($payload['message'] ?? null);
        if (! ($response->successful() && $apiStatusCode === 'R200')) {
            return ['ok' => false, 'api_status_message' => $this->safeFailureMessage($apiStatusCode, $apiStatusMessage, $response->status()), 'http_response_code' => 502];
        }

        return ['ok' => true, 'orders' => $this->ordersFromPayload($payload)];
    }

    public function validateDates(string $from, ?string $to = null): ?string
    {
        $to ??= now()->toDateString();
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return "Invalid {$field} date. Expected Y-m-d.";
            }
        }
        return CarbonImmutable::parse($from)->gt(CarbonImmutable::parse($to)) ? 'The from date must be before or equal to the to date.' : null;
    }

    private function summarizeImport(array $orders): array
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

        $existing = $createOrders = $createItems = $unresolved = $parts = [];
        $alreadyZero = $conflicts = $unmatched = 0;
        foreach ($orders as $order) {
            $order = (array) $order;
            $ovokoOrderId = $this->orderId($order);
            $existingOrder = $this->findExistingOrder($ovokoOrderId);
            $orderSample = $this->orderImportSample($order, $existingOrder?->id);
            if ($existingOrder) {
                $existing[] = $orderSample;
                continue;
            }
            $createOrders[] = $orderSample;

            foreach ($this->itemsFromOrder($order) as $item) {
                $ovokoPartId = $this->extractPartId($item);
                $listing = $ovokoPartId ? $listings->get((string) $ovokoPartId) : null;
                $action = $this->importAction($listing);
                $sample = $this->orderItemImportSample($order, $item, $ovokoPartId, $listing, $action);
                $createItems[] = $sample;
                if (str_contains($action, 'unresolved')) {
                    $unresolved[] = $sample;
                }
                if ($action === 'would_create_order_item_and_mark_part_sold') {
                    $parts[$listing->part_id] = $sample;
                } elseif ($action === 'would_create_order_item_part_already_zero') {
                    $alreadyZero++;
                } elseif ($action === 'would_create_order_item_unresolved_conflict') {
                    $conflicts++;
                } elseif ($action === 'would_create_order_item_unmatched') {
                    $unmatched++;
                }
            }
        }

        return [
            'orders_count' => count($orders),
            'order_items_count' => count($items),
            'existing_orders_count' => count($existing),
            'would_create_orders_count' => count($createOrders),
            'would_skip_existing_orders_count' => count($existing),
            'would_create_order_items_count' => count($createItems),
            'would_create_unresolved_order_items_count' => count($unresolved),
            'would_mark_sold_count' => count($parts),
            'would_set_quantity_zero_count' => count($parts),
            'already_zero_items_count' => $alreadyZero,
            'conflict_items_count' => $conflicts,
            'unmatched_items_count' => $unmatched,
            'samples_would_create_orders' => array_slice($createOrders, 0, self::SAMPLE_LIMIT),
            'samples_existing_orders' => array_slice($existing, 0, self::SAMPLE_LIMIT),
            'samples_would_create_order_items' => array_slice($createItems, 0, self::SAMPLE_LIMIT),
            'samples_unresolved_items' => array_slice($unresolved, 0, self::SAMPLE_LIMIT),
            'samples_would_update_parts' => array_slice(array_values($parts), 0, self::SAMPLE_LIMIT),
        ];
    }


    private function unmatchedExportRows(array $orders): array
    {
        $items = [];
        foreach ($orders as $order) {
            foreach ($this->itemsFromOrder((array) $order) as $item) {
                $items[] = ['order' => (array) $order, 'item' => $item, 'ovoko_part_id' => $this->extractPartId($item)];
            }
        }

        $partIds = collect($items)->pluck('ovoko_part_id')->filter()->unique()->values();
        $listings = MarketplaceListing::query()->where('marketplace', 'ovoko')->whereIn('external_offer_id', $partIds)->pluck('id', 'external_offer_id');
        $rows = [];
        foreach ($items as $row) {
            $ovokoPartId = $row['ovoko_part_id'];
            if ($ovokoPartId && $listings->has((string) $ovokoPartId)) {
                continue;
            }

            $order = $row['order'];
            $item = $row['item'];
            $rows[] = [
                'ovoko_order_id' => $this->orderId($order),
                'order_date' => $order['order_date'] ?? $order['created_at'] ?? $order['date'] ?? '',
                'buyer_country' => $order['client_address_country'] ?? $order['buyer_country'] ?? data_get($order, 'buyer.country', ''),
                'ovoko_part_id' => $ovokoPartId,
                'item_name' => $item['name'] ?? data_get($item, 'item.name', data_get($item, 'part.name', data_get($item, 'product.name', ''))),
                'unit_price' => data_get($item, 'sell_price.seller.amount', data_get($item, 'price.seller.amount', $item['price'] ?? '')),
                'currency' => data_get($item, 'sell_price.seller.currency', data_get($item, 'price.seller.currency', $item['currency'] ?? '')),
                'order_total_seller_amount' => data_get($order, 'total_price.seller.amount', data_get($order, 'total.seller.amount', $order['total_price'] ?? $order['total'] ?? '')),
                'order_total_seller_currency' => data_get($order, 'total_price.seller.currency', data_get($order, 'total.seller.currency', $order['currency'] ?? '')),
                'match_status' => 'unmatched',
                'reason' => 'no marketplace listing for ovoko_part_id',
                'laravel_part_id' => '',
                'sku' => $item['sku'] ?? data_get($item, 'item.sku', data_get($item, 'part.sku', data_get($item, 'product.sku', ''))),
                'notes' => '',
                'manual_action' => '',
            ];
        }

        return $rows;
    }

    private function findExistingOrder(?string $ovokoOrderId): ?object
    {
        if (! $ovokoOrderId || ! Schema::hasTable('orders')) return null;
        $query = DB::table('orders')->select('id');
        if (Schema::hasColumn('orders', 'external_order_id')) {
            $query->where('external_order_id', $ovokoOrderId);
        } else {
            $query->where(function ($q) use ($ovokoOrderId): void {
                $q->where('meta->ovoko_order_id', $ovokoOrderId)
                    ->orWhere('meta->external_order_id', $ovokoOrderId);
            })->where('meta->source', 'ovoko');
        }
        return $query->first();
    }

    private function orderImportSample(array $order, ?int $existingOrderId): array
    {
        $amount = data_get($order, 'total_price.seller.amount', data_get($order, 'total.seller.amount', $order['total_price'] ?? $order['total'] ?? null));
        $currency = data_get($order, 'total_price.seller.currency', data_get($order, 'total.seller.currency', $order['currency'] ?? null));
        return [
            'ovoko_order_id' => $this->orderId($order),
            'order_date' => $order['order_date'] ?? $order['created_at'] ?? $order['date'] ?? null,
            'buyer_country' => $order['client_address_country'] ?? $order['buyer_country'] ?? data_get($order, 'buyer.country'),
            'total_seller_amount' => $amount,
            'total_seller_currency' => $currency,
            'items_count' => count($this->itemsFromOrder($order)),
            'would_create' => $existingOrderId === null,
            'existing_order_id' => $existingOrderId,
            'mapped_order' => [
                'status' => 'new',
                'source' => 'ovoko',
                'marketplace' => 'ovoko',
                'external_order_id_target' => Schema::hasColumn('orders', 'external_order_id') ? 'external_order_id' : 'meta.ovoko_order_id',
            ],
        ];
    }

    private function orderItemImportSample(array $order, array $item, ?string $ovokoPartId, ?MarketplaceListing $listing, string $action): array
    {
        $part = $listing?->part;
        $price = data_get($item, 'sell_price.seller.amount', data_get($item, 'price.seller.amount', $item['price'] ?? null));
        $currency = data_get($item, 'sell_price.seller.currency', data_get($item, 'price.seller.currency', $item['currency'] ?? null));
        return [
            'ovoko_order_id' => $this->orderId($order),
            'ovoko_part_id' => $ovokoPartId,
            'laravel_part_id' => $listing?->part_id,
            'sku' => $part?->sku ?? $listing?->sku ?? ($item['sku'] ?? null),
            'title' => $item['name'] ?? $part?->name ?? $listing?->title ?? null,
            'quantity' => (int) ($item['quantity'] ?? 1),
            'unit_price' => $price,
            'currency' => $currency,
            'current_quantity' => $part?->quantity ?? $listing?->quantity,
            'current_status' => $part?->status ?? $listing?->status,
            'dry_run_action' => $action,
        ];
    }

    private function importAction(?MarketplaceListing $listing): string
    {
        if (! $listing) return 'would_create_order_item_unmatched';
        if ($listing->part_id === null || $listing->sync_status === 'conflict' || $listing->match_status === 'conflict' || ! $listing->part) return 'would_create_order_item_unresolved_conflict';
        if ((int) $listing->part->quantity <= 0) return 'would_create_order_item_part_already_zero';
        return 'would_create_order_item_and_mark_part_sold';
    }

    private function validateConfiguration(MarketplaceAccount $account, bool $credentialsConfigured): ?string
    {
        if (! $account->api_enabled) return 'Ovoko API is not enabled for ovoko_main.';
        if (blank($account->api_base_url)) return 'Ovoko API base URL is missing.';
        if ($account->api_mode !== 'dry_run') return 'Ovoko orders import dry-run is allowed only in dry_run mode.';
        if (! $credentialsConfigured) return 'Ovoko API credentials are not fully configured.';
        return null;
    }

    private function endpointUsed(string $baseUrl, string $from, string $to): string { return rtrim($baseUrl, '/').self::ENDPOINT_PATH.'/'.$from.'/'.$to; }
    private function ordersFromPayload(array $payload): array { $orders = $payload['list'] ?? $payload['data'] ?? $payload['orders'] ?? []; return is_array($orders) ? array_values($orders) : []; }
    private function itemsFromOrder(array $order): array { $items = $order['item_list'] ?? $order['items'] ?? $order['parts'] ?? []; return is_array($items) ? array_map(fn ($item) => (array) $item, array_values($items)) : []; }
    private function extractPartId(array $item): ?string { foreach (['id', 'part_id', 'rrr_part_id', 'external_id', 'part.id', 'part.part_id', 'part.rrr_part_id', 'item.id', 'item.part_id', 'product.id', 'product.part_id', 'listing_id', 'listing.id', 'product.listing_id', 'id_bridge'] as $path) { $value = data_get($item, $path); if (is_scalar($value) && filled($value) && trim((string) $value) !== '0') return trim((string) $value); } return null; }
    private function orderId(array $order): ?string { $value = $order['order_id'] ?? $order['id'] ?? null; return is_scalar($value) && filled($value) ? (string) $value : null; }
    private function safeFailureMessage(mixed $apiStatusCode, mixed $apiStatusMessage, int $httpStatus): string { return "HTTP {$httpStatus}; API status ".($apiStatusCode ?: 'missing').'; '.(filled($apiStatusMessage) ? (string) $apiStatusMessage : 'Ovoko/RRR API returned a non-success status.'); }
}

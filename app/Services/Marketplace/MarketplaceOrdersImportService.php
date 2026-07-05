<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\MarketplaceOrderTimeService;
use App\Support\Marketplace\EbayOAuthConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class MarketplaceOrdersImportService
{
    public function __construct(private readonly MarketplaceOrderTimeService $timeService) {}
    public const LIVE_BATCH = 'manual_marketplace_orders_live';
    public const TEST_BATCH = 'marketplace_orders_ui_test';

    public function run(array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $requestedChannels = $this->requestedChannels((string) ($options['marketplace'] ?? ($options['channels'] ?? 'all')));
        $marketplaces = $this->normalizeOrderImportChannels($requestedChannels);
        $summary = $this->emptySummary($options, $dryRun);
        $summary['requested_channels'] = $requestedChannels;
        $summary['normalized_channels'] = $marketplaces;
        if ($this->requestedEbayMarketChannels($requestedChannels) !== [] && in_array('ebay', $marketplaces, true)) {
            $summary['warnings'][] = ['marketplace' => 'ebay', 'code' => 'ebay_shared_order_feed', 'message' => 'eBay DE/FR share the same order feed; orders were imported once as ebay.'];
            $options['ebay_shared_order_feed_warning'] = true;
        }

        foreach ($marketplaces as $marketplace) {
            $summary['marketplaces'][$marketplace] = $this->runMarketplace($marketplace, $options, $dryRun);
            foreach (['orders_fetched','orders_created','orders_updated','orders_skipped','items_created','items_updated'] as $key) {
                $summary[$key] += $summary['marketplaces'][$marketplace][$key] ?? 0;
            }
            $summary['errors'] = array_merge($summary['errors'], $summary['marketplaces'][$marketplace]['errors'] ?? []);
            $summary['warnings'] = array_merge($summary['warnings'], $summary['marketplaces'][$marketplace]['warnings'] ?? []);
        }

        return $summary;
    }

    public function deleteTestBatch(string $sourceBatch, bool $dryRun, bool $confirm): array
    {
        $query = Order::query()->where('test_import', true)->where('source_batch', $sourceBatch);
        $count = (clone $query)->count();
        $sample = (clone $query)->limit(10)->pluck('order_number')->all();
        $deleted = 0;
        if (! $dryRun && $confirm && $sourceBatch === self::TEST_BATCH) {
            $deleted = $query->delete();
        }

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'confirm' => $confirm,
            'source_batch' => $sourceBatch,
            'matched_orders' => $count,
            'deleted_orders' => $deleted,
            'sample_order_numbers' => $sample,
            'safety_flags' => [
                'read_only' => $dryRun,
                'orders_changed' => $deleted > 0,
                'products_changed' => false,
                'offers_changed' => false,
                'mappings_changed' => false,
                'parts_changed' => false,
                'allegro_write' => false,
                'ovoko_write' => false,
                'ebay_write' => false,
            ],
        ];
    }

    private function runMarketplace(string $marketplace, array $options, bool $dryRun): array
    {
        $result = $this->emptyMarketplaceSummary($marketplace, $options, $dryRun);
        if ($marketplace === 'ebay' && ($options['ebay_shared_order_feed_warning'] ?? false)) {
            $result['warnings'][] = ['marketplace' => 'ebay', 'code' => 'ebay_shared_order_feed', 'message' => 'eBay DE/FR share the same order feed; orders were imported once as ebay.'];
        }
        try {
            $orders = $this->dedupeFetchedOrders($marketplace, $this->fetchOrders($marketplace, $options, $result), $result);
            $result['orders_fetched'] = count($orders);
            foreach ($orders as $raw) {
                $normalized = $this->normalizeOrder($marketplace, $raw, $result);
                if (($normalized['marketplace_order_id'] ?? '') === '') { $result['orders_skipped']++; continue; }
                if (($normalized['ordered_at'] ?? null) === null) {
                    $result['warnings'][] = ['marketplace' => $marketplace, 'code' => 'missing_ordered_at', 'marketplace_order_id' => $normalized['marketplace_order_id']];
                }
                if ($dryRun) {
                    $preview = Arr::only($normalized, ['marketplace','provider','marketplace_order_id','dedupe_key','source_marketplace_id','marketplace_status','ordered_at','buyer_name','total_amount','delivery_amount','currency','amount_source','total_amount_source','delivery_amount_source']);
                    $preview['ordered_at_utc'] = $normalized['ordered_at_utc'] ?? null;
                    $preview['ordered_at_local'] = $normalized['ordered_at_local'] ?? null;
                    $preview['timezone'] = MarketplaceOrderTimeService::LOCAL_TIMEZONE;
                    $result['would_import'][] = $preview;
                    continue;
                }
                $this->upsertOrder($normalized, $raw, $result, (bool) ($options['live_import'] ?? false));
            }
        } catch (Throwable $e) {
            app(ApiIntegrationLogger::class)->error($marketplace, 'marketplace_orders_import', $e, [
                'request' => [
                    'dry_run' => $dryRun,
                    'limit' => $options['limit'] ?? null,
                    'since' => $options['since'] ?? null,
                ],
            ]);
            $result['errors'][] = ['marketplace' => $marketplace, 'message' => 'Marketplace orders read failed without exposing secrets.', 'exception' => $e::class];
        }
        return $result;
    }

    private function fetchOrders(string $marketplace, array $options, array &$result): array
    {
        $account = $this->accountForOrders($marketplace);
        if (! $account || ! $account->api_enabled || blank($account->api_base_url)) {
            $result['warnings'][] = 'Marketplace account API is not configured/enabled.';
            return [];
        }
        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
        $limit = max(1, min((int) ($options['limit'] ?? 50), 100));
        $query = $this->orderQuery($marketplace, $options, $limit);
        $base = rtrim((string) $account->api_base_url, '/');
        $endpointPath = $marketplace === 'allegro' ? '/order/checkout-forms' : '/sell/fulfillment/v1/order';
        $diagnostics = $this->orderAuthDiagnostics($marketplace, $account, $endpointPath);

        if ($marketplace === 'allegro') {
            $response = $this->sendAllegroOrdersRequest($base, (string) ($credentials['access_token'] ?? ''), $query);
            if ($response->status() === 401) {
                $diagnostics['refresh_attempted'] = filled($credentials['refresh_token'] ?? null);
                $refresh = $diagnostics['refresh_attempted'] ? (new AllegroApiClient((string) $account->code, $account))->refreshAccessToken() : ['ok' => false];
                $diagnostics['refreshed'] = ($refresh['ok'] ?? false) === true;
                if ($diagnostics['refreshed']) {
                    $account->refresh();
                    $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
                    $diagnostics['token_expires_at'] = $this->tokenExpiresAt($marketplace, $credentials);
                    $response = $this->sendAllegroOrdersRequest($base, (string) ($credentials['access_token'] ?? ''), $query);
                }
            }
        } elseif (in_array($marketplace, ['ebay', 'ebay_de', 'ebay_fr'], true)) {
            $marketplaceId = (string) (($account->api_settings ?? [])['marketplace_id'] ?? ($account->code === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE'));
            $result['source_account_code'] = (string) $account->code;
            $result['requested_marketplace_id'] = $marketplaceId;
            $response = $this->sendEbayOrdersRequest($base, (string) ($credentials['access_token'] ?? ''), $marketplaceId, $query);
            if ($response->status() === 401) {
                $diagnostics['refresh_attempted'] = $this->canRefreshEbayToken($credentials);
                $newToken = $diagnostics['refresh_attempted'] ? $this->refreshEbayAccessToken($account, $credentials) : null;
                $diagnostics['refreshed'] = filled($newToken);
                if ($diagnostics['refreshed']) {
                    $account->refresh();
                    $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
                    $diagnostics['token_expires_at'] = $this->tokenExpiresAt($marketplace, $credentials);
                    $diagnostics['scopes'] = $this->safeScopes($credentials);
                    $response = $this->sendEbayOrdersRequest($base, (string) $newToken, $marketplaceId, $query);
                }
            }
        } else {
            return $this->fetchOvokoOrders($base, $credentials, $options, $result);
        }

        $payload = is_array($response->json()) ? $response->json() : [];
        $result['api_http_status'] = $response->status();
        $diagnostics['http_status'] = $response->status();
        app(ApiIntegrationLogger::class)->record([
            'integration' => $marketplace, 'action' => 'GET orders', 'status' => $response->successful() ? 'success' : 'error',
            'http_status' => $response->status(), 'message' => 'Read-only marketplace order fetch.',
            'request' => ['endpoint' => $endpointPath, 'query' => $query, 'requested_channels' => $result['requested_channels'] ?? null, 'normalized_channels' => $result['normalized_channels'] ?? null, 'source_account_code' => $result['source_account_code'] ?? null, 'requested_marketplace_id' => $result['requested_marketplace_id'] ?? null, 'auth_diagnostics' => $diagnostics],
            'response' => ['keys' => is_array($payload) ? array_keys($payload) : []],
        ]);
        if (! $response->successful()) {
            $result['errors'][] = [
                'marketplace' => $marketplace,
                'http_status' => $response->status(),
                'message' => $this->ordersAuthErrorMessage($marketplace, $response->status(), $credentials),
            ];
            return [];
        }
        return array_values(array_filter($payload['checkoutForms'] ?? $payload['orders'] ?? $payload['data'] ?? $payload['list'] ?? [], 'is_array'));
    }

    private function sendAllegroOrdersRequest(string $base, string $token, array $query): \Illuminate\Http\Client\Response
    {
        return Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(25)->get($base.'/order/checkout-forms', $query);
    }

    private function sendEbayOrdersRequest(string $base, string $token, string $marketplaceId, array $query): \Illuminate\Http\Client\Response
    {
        return Http::withToken($token)->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId])->acceptJson()->timeout(25)->get($base.'/sell/fulfillment/v1/order', $query);
    }

    private function fetchOvokoOrders(string $base, array $credentials, array $options, array &$result): array
    {
        $dateFrom = $this->dateString($options['date_from'] ?? ($options['since'] ?? null)) ?? Carbon::today()->toDateString();
        $dateTo = $this->dateString($options['date_to'] ?? null) ?? Carbon::today()->toDateString();
        $endpointPath = '/v2/get/orders/'.$dateFrom.'/'.$dateTo;
        $endpoint = $base.$endpointPath;
        $authFields = Arr::only($credentials, ['username', 'password', 'user_token']);

        $response = Http::asForm()->acceptJson()->timeout(25)->post($endpoint, $authFields);
        $json = $response->json();
        $payload = is_array($json) ? $json : [];
        $list = $payload['list'] ?? null;
        $orders = is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
        $statusCode = $payload['status_code'] ?? null;
        $msg = $payload['msg'] ?? ($payload['message'] ?? null);

        $result['api_http_status'] = $response->status();
        $result['ovoko_status_code'] = $statusCode;
        $result['ovoko_msg'] = $msg;

        if ($options['include_debug'] ?? false) {
            $result['debug'] = [
                'api_base_url' => $base,
                'endpoint_path' => $endpointPath,
                'request_method' => 'POST',
                'request_params_sanitized' => ['auth_fields' => array_keys($authFields), 'content_type' => 'form-data'],
                'date_from_used' => $dateFrom,
                'date_to_used' => $dateTo,
                'api_http_status' => $response->status(),
                'ovoko_status_code' => $statusCode,
                'ovoko_msg' => $msg,
                'raw_response_type' => is_array($json) ? 'json_object' : gettype($json),
                'raw_response_keys' => array_values(array_slice(array_keys($payload), 0, 30)),
                'raw_list_count' => is_array($list) ? count($list) : null,
                'mapper_source_key' => 'list',
                'mapper_items_count' => count($orders),
                'sample_raw_order_sanitized' => array_map(fn (array $order): array => $this->sanitizePayload($order), array_slice($orders, 0, 2)),
                'empty_response_reason' => is_array($list) && count($list) === 0 ? 'status_code_R200_but_list_empty' : null,
            ];
        }

        if (! $response->successful()) {
            $result['errors'][] = ['marketplace' => 'ovoko', 'http_status' => $response->status(), 'message' => 'Read-only order endpoint returned non-success HTTP status.'];
            return [];
        }
        if ($statusCode !== 'R200') {
            $result['warnings'][] = ['marketplace' => 'ovoko', 'code' => 'ovoko_non_success_status_code', 'ovoko_status_code' => $statusCode, 'ovoko_msg' => $msg];
            return [];
        }
        if (! array_key_exists('list', $payload) || ! is_array($list)) {
            $result['warnings'][] = ['marketplace' => 'ovoko', 'code' => 'ovoko_unrecognized_response_shape', 'ovoko_status_code' => $statusCode, 'ovoko_msg' => $msg];
            return [];
        }
        if (count($list) > 0 && count($orders) === 0) {
            $result['warnings'][] = ['marketplace' => 'ovoko', 'code' => 'ovoko_mapper_detected_unparsed_items'];
        } elseif ($orders === []) {
            $result['warnings'][] = ['marketplace' => 'ovoko', 'code' => 'ovoko_empty_orders_response', 'ovoko_status_code' => $statusCode, 'ovoko_msg' => $msg];
        }

        return $orders;
    }

    private function normalizeOrder(string $marketplace, array $raw, array &$result): array
    {
        if ($marketplace === 'ovoko') {
            return $this->normalizeOvokoOrder($raw, $result);
        }
        $buyer = $raw['buyer'] ?? $raw['buyerInfo'] ?? [];
        $delivery = $raw['delivery'] ?? $raw['fulfillmentStartInstructions'][0]['shippingStep'] ?? [];
        $address = $delivery['address'] ?? $raw['shippingAddress'] ?? [];
        $items = $raw['lineItems'] ?? $raw['line_items'] ?? $raw['items'] ?? [];
        $total = $raw['summary']['totalToPay'] ?? $raw['pricingSummary']['total'] ?? $raw['total'] ?? [];
        $shipping = $raw['summary']['delivery'] ?? $raw['pricingSummary']['deliveryCost'] ?? $raw['deliveryCost'] ?? [];
        $orderedAtUtc = $this->orderedAtUtc($marketplace, $raw, array_values(array_filter($items, 'is_array')));
        $orderedAtLocal = $this->timeService->marketplaceUtcToLocalStorage($orderedAtUtc);

        return [
            'marketplace' => $this->orderProvider($marketplace),
            'provider' => $this->orderProvider($marketplace),
            'marketplace_order_id' => (string) ($raw['id'] ?? $raw['orderId'] ?? $raw['order_id'] ?? ''),
            'source_marketplace_id' => $this->responseMarketplaceId($raw),
            'marketplace_status' => (string) ($raw['status'] ?? $raw['orderFulfillmentStatus'] ?? ''),
            'ordered_at_utc' => $this->timeService->marketplaceUtcIso($orderedAtUtc),
            'ordered_at_local' => $orderedAtLocal,
            'ordered_at' => $orderedAtLocal,
            'buyer_name' => trim((string) ($buyer['login'] ?? $buyer['username'] ?? $buyer['fullName'] ?? $buyer['name'] ?? $raw['buyer_name'] ?? 'Marketplace buyer')),
            'buyer_email' => (string) ($buyer['email'] ?? $raw['buyer_email'] ?? ''),
            'buyer_phone' => (string) ($buyer['phoneNumber'] ?? $buyer['phone'] ?? $address['phoneNumber'] ?? ''),
            'delivery_name' => (string) ($address['fullName'] ?? $address['name'] ?? $raw['delivery_name'] ?? ''),
            'delivery_address' => trim((string) (($address['street'] ?? $address['addressLine1'] ?? $address['line1'] ?? '').' '.($address['addressLine2'] ?? ''))),
            'delivery_postcode' => (string) ($address['zipCode'] ?? $address['postalCode'] ?? $address['postcode'] ?? ''),
            'delivery_city' => (string) ($address['city'] ?? ''),
            'delivery_country' => (string) ($address['countryCode'] ?? $address['country'] ?? 'PL'),
            'invoice_data' => $raw['invoice'] ?? $raw['billingAddress'] ?? null,
            'currency' => (string) ($total['currency'] ?? $raw['currency'] ?? 'PLN'),
            'total_amount' => (float) ($total['amount'] ?? $total['value'] ?? $raw['total_amount'] ?? 0),
            'delivery_amount' => (float) ($shipping['amount'] ?? $shipping['value'] ?? $raw['delivery_amount'] ?? 0),
            'payment_status' => (string) ($raw['payment']['status'] ?? $raw['paymentSummary']['payments'][0]['paymentStatus'] ?? ''),
            'delivery_method' => (string) ($delivery['method']['name'] ?? $delivery['shippingCarrierCode'] ?? $raw['delivery_method'] ?? ''),
            'items' => array_values(array_filter($items, 'is_array')),
            'raw_payload' => $raw,
            'dedupe_key' => $this->orderProvider($marketplace).'|'.(string) ($raw['id'] ?? $raw['orderId'] ?? $raw['order_id'] ?? ''),
        ];
    }

    private function normalizeOvokoOrder(array $raw, array &$result): array
    {
        $items = array_values(array_filter($raw['item_list'] ?? [], 'is_array'));
        $hasTotal = array_key_exists('total_price', $raw) || array_key_exists('total_amount', $raw);
        $hasDelivery = array_key_exists('shipping_price', $raw) || array_key_exists('delivery_amount', $raw);
        $total = $this->ovokoAmount($raw['total_price'] ?? ($raw['total_amount'] ?? null), ['seller', 'buyer'], 'total_price', $hasTotal);
        $delivery = $this->ovokoAmount($raw['shipping_price'] ?? ($raw['delivery_amount'] ?? null), ['seller', 'buyer'], 'shipping_price', $hasDelivery);
        $currency = (string) ($total['currency'] ?? $raw['currency'] ?? $raw['total_price_currency'] ?? $raw['price_currency'] ?? 'EUR');

        foreach ([['total', $total], ['delivery', $delivery]] as [$kind, $amount]) {
            if (! $amount['resolved']) {
                $result['warnings'][] = [
                    'marketplace' => 'ovoko',
                    'code' => 'ovoko_amount_unresolved',
                    'marketplace_order_id' => (string) ($raw['order_id'] ?? ''),
                    'amount_kind' => $kind,
                    'amount_source' => $amount['amount_source'],
                    'currency_source' => $amount['currency_source'],
                ];
            }
        }

        return [
            'marketplace' => 'ovoko',
            'marketplace_order_id' => (string) ($raw['order_id'] ?? ''),
            'marketplace_status' => (string) ($raw['order_status'] ?? ''),
            'ordered_at' => $this->validDateString($raw['order_date'] ?? null),
            'buyer_name' => (string) ($raw['client_name'] ?? ''),
            'buyer_email' => (string) ($raw['client_email'] ?? ''),
            'buyer_phone' => (string) ($raw['client_phone'] ?? ''),
            'delivery_name' => (string) ($raw['client_name'] ?? ''),
            'delivery_address' => (string) ($raw['client_address'] ?? $raw['shipping_address'] ?? $raw['delivery_address'] ?? ''),
            'delivery_postcode' => (string) ($raw['client_postcode'] ?? $raw['shipping_postcode'] ?? $raw['delivery_postcode'] ?? ''),
            'delivery_city' => (string) ($raw['client_city'] ?? $raw['shipping_city'] ?? $raw['delivery_city'] ?? ''),
            'delivery_country' => (string) ($raw['client_country'] ?? $raw['shipping_country'] ?? $raw['delivery_country'] ?? ''),
            'invoice_data' => $raw['invoice'] ?? $raw['company'] ?? null,
            'currency' => $currency,
            'total_amount' => $total['amount'],
            'delivery_amount' => $delivery['amount'],
            'amount_source' => $total['amount_source'],
            'total_amount_source' => $total['amount_source'],
            'delivery_amount_source' => $delivery['amount_source'],
            'payment_status' => (string) ($raw['payment_status'] ?? ''),
            'payment_method' => (string) ($raw['payment_type'] ?? $raw['payment_method'] ?? ''),
            'delivery_method' => (string) ($raw['shipping_method'] ?? $raw['delivery_method'] ?? ''),
            'items' => array_map(fn (array $item, int $idx): array => $this->normalizeOvokoItem($item, (string) ($raw['order_id'] ?? ''), $idx, $currency, $result), $items, array_keys($items)),
            'raw_payload' => $raw,
        ];
    }

    private function normalizeOvokoItem(array $raw, string $orderId, int $idx, string $currency, array &$result): array
    {
        $title = (string) ($raw['title'] ?? $raw['name'] ?? $raw['part_name'] ?? 'Ovoko item');
        $hasPrice = array_key_exists('sell_price', $raw) || array_key_exists('price', $raw) || array_key_exists('unit_price', $raw) || array_key_exists('total_price', $raw);
        $priceAmount = $this->ovokoAmount($raw['sell_price'] ?? ($raw['price'] ?? ($raw['unit_price'] ?? ($raw['total_price'] ?? null))), ['seller', 'buyer'], 'item_list.sell_price', $hasPrice);
        if (! $priceAmount['resolved']) {
            $result['warnings'][] = [
                'marketplace' => 'ovoko',
                'code' => 'ovoko_amount_unresolved',
                'marketplace_order_id' => $orderId,
                'amount_kind' => 'item_price',
                'item_index' => $idx,
                'amount_source' => $priceAmount['amount_source'],
                'currency_source' => $priceAmount['currency_source'],
            ];
        }
        $price = $priceAmount['amount'];
        $id = (string) ($raw['item_id'] ?? $raw['id'] ?? $raw['part_id'] ?? $raw['ovoko_part_id'] ?? '');
        if ($id === '') $id = hash('sha256', $orderId.'|'.$idx.'|'.$title.'|'.$price);
        return $raw + [
            'marketplace_item_id' => $id,
            'title' => $title,
            'quantity' => (int) ($raw['quantity'] ?? $raw['qty'] ?? 1),
            'price' => $price,
            'currency' => (string) ($priceAmount['currency'] ?? $raw['currency'] ?? $currency),
            'amount_source' => $priceAmount['amount_source'],
            'raw_payload' => $raw,
        ];
    }


    private function orderedAtUtc(string $marketplace, array $raw, array $items): ?string
    {
        $orderLevelKeys = $marketplace === 'allegro'
            ? ['boughtAt', 'orderedAt', 'purchasedAt', 'createdAt', 'creationDate', 'created_at', 'checkoutCompletedAt']
            : ['boughtAt', 'orderedAt', 'purchasedAt', 'creationDate', 'createdAt', 'created_at'];

        foreach ($orderLevelKeys as $key) {
            $value = $this->validDateString($raw[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        if ($marketplace === 'allegro') {
            $boughtAt = [];
            foreach ($items as $item) {
                $value = $this->validDateString($item['boughtAt'] ?? null);
                if ($value !== null) {
                    $boughtAt[] = $value;
                }
            }
            if ($boughtAt !== []) {
                sort($boughtAt);
                return $boughtAt[0];
            }

            foreach (['updatedAt', 'revision'] as $key) {
                $value = $this->validDateString($raw[$key] ?? null);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function validDateString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtotime($value) === false ? null : $value;
    }

    private function dateString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') return null;
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function sanitizePayload(array $payload): array
    {
        $secretKeys = ['password', 'token', 'user_token', 'access_token', 'refresh_token', 'authorization'];
        $sanitized = [];
        foreach ($payload as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $secretKeys, true) || str_contains($lower, 'token') || str_contains($lower, 'password')) {
                $sanitized[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    private function ovokoAmount(mixed $value, array $preferredSides, string $field, bool $inputPresent = true): array
    {
        if (is_array($value)) {
            foreach ($preferredSides as $side) {
                if (isset($value[$side]) && is_array($value[$side])) {
                    $amount = $value[$side]['amount'] ?? $value[$side]['value'] ?? null;
                    if (is_numeric($amount)) {
                        return [
                            'resolved' => true,
                            'amount' => (float) $amount,
                            'currency' => isset($value[$side]['currency']) ? (string) $value[$side]['currency'] : null,
                            'amount_source' => $side,
                            'currency_source' => isset($value[$side]['currency']) ? $field.'.'.$side.'.currency' : null,
                        ];
                    }
                }
            }

            $amount = $value['amount'] ?? $value['value'] ?? null;
            if (is_numeric($amount)) {
                return [
                    'resolved' => true,
                    'amount' => (float) $amount,
                    'currency' => isset($value['currency']) ? (string) $value['currency'] : null,
                    'amount_source' => 'scalar',
                    'currency_source' => isset($value['currency']) ? $field.'.currency' : null,
                ];
            }

            return ['resolved' => ! $inputPresent, 'amount' => 0.0, 'currency' => null, 'amount_source' => $field, 'currency_source' => null];
        }

        if (is_numeric($value)) {
            return ['resolved' => true, 'amount' => (float) $value, 'currency' => null, 'amount_source' => 'scalar', 'currency_source' => null];
        }

        return ['resolved' => ! $inputPresent, 'amount' => 0.0, 'currency' => null, 'amount_source' => $field, 'currency_source' => null];
    }

    private function amountValue(mixed $value): float
    {
        if (is_array($value)) {
            return (float) ($value['amount'] ?? $value['value'] ?? 0);
        }
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function upsertOrder(array $n, array $raw, array &$result, bool $liveImport): void
    {
        DB::transaction(function () use ($n, $raw, &$result, $liveImport): void {
            $order = Order::query()->firstOrNew(['marketplace' => $n['marketplace'], 'marketplace_order_id' => $n['marketplace_order_id']]);
            $created = ! $order->exists;
            $order->fill([
                'order_number' => $order->order_number ?: strtoupper($n['marketplace']).'-'.$n['marketplace_order_id'],
                'marketplace_status' => $n['marketplace_status'], 'ordered_at' => $n['ordered_at'], 'status' => $this->localStatus($n['marketplace_status']),
                'currency' => substr($n['currency'], 0, 3), 'subtotal' => max(0, $n['total_amount'] - $n['delivery_amount']), 'shipping_total' => $n['delivery_amount'], 'total' => $n['total_amount'],
                'payment_status' => $n['payment_status'], 'delivery_method' => $n['delivery_method'], 'customer_name' => $n['buyer_name'], 'email' => $n['buyer_email'] ?: 'marketplace-'.$n['marketplace_order_id'].'@example.invalid',
                'phone' => $n['buyer_phone'] ?: '-', 'address_line1' => $n['delivery_address'] ?: '-', 'postal_code' => $n['delivery_postcode'] ?: '-', 'city' => $n['delivery_city'] ?: '-', 'country' => substr($n['delivery_country'] ?: 'PL', 0, 2),
                'invoice_data' => $n['invoice_data'], 'raw_payload' => $raw, 'imported_at' => $order->imported_at ?: now(), 'test_import' => ! $liveImport, 'source_batch' => $liveImport ? self::LIVE_BATCH : self::TEST_BATCH,
                'notes' => trim(($liveImport ? '' : 'TEST IMPORT marketplace order. ').(string) ($order->notes ?? '')),
            ])->save();
            $created ? $result['orders_created']++ : $result['orders_updated']++;
            foreach ($n['items'] as $idx => $item) $this->upsertItem($order, $item, $idx, $result);
        });
    }

    private function upsertItem(Order $order, array $raw, int $idx, array &$result): void
    {
        $id = (string) ($raw['marketplace_item_id'] ?? $raw['id'] ?? $raw['lineItemId'] ?? $raw['item_id'] ?? $idx);
        $price = $raw['price'] ?? $raw['unitPrice'] ?? $raw['lineItemCost'] ?? [];
        $qty = (int) ($raw['quantity'] ?? $raw['quantityPurchased'] ?? 1);
        $unit = $this->amountValue($price ?: ($raw['unit_price'] ?? 0));
        $item = $order->items()->firstOrNew(['marketplace' => $order->marketplace, 'marketplace_order_id' => $order->marketplace_order_id, 'marketplace_item_id' => $id]);
        $created = ! $item->exists;
        $item->fill(['product_name' => (string) ($raw['offer']['name'] ?? $raw['title'] ?? $raw['name'] ?? $raw['legacyItemId'] ?? 'Marketplace item'), 'sku' => (string) ($raw['offer']['external']['id'] ?? $raw['sku'] ?? ''), 'offer_id' => (string) ($raw['offer']['id'] ?? $raw['part_id'] ?? $raw['ovoko_part_id'] ?? $raw['legacyItemId'] ?? ''), 'external_product_id' => (string) ($raw['productId'] ?? ''), 'unit_price' => $unit, 'quantity' => max(1, $qty), 'line_total' => $this->amountValue($raw['total_price'] ?? ($unit * max(1, $qty))), 'currency' => (string) ($raw['currency'] ?? (is_array($price) ? ($price['currency'] ?? null) : null) ?? $order->currency), 'raw_payload' => $raw['raw_payload'] ?? $raw])->save();
        $created ? $result['items_created']++ : $result['items_updated']++;
    }

    private function requestedChannels(string $marketplace): array { $requested = $marketplace === 'all' ? ['allegro', 'ebay_de', 'ebay_fr', 'ovoko'] : array_map('trim', explode(',', $marketplace)); return array_values(array_intersect($requested, ['allegro', 'ebay', 'ebay_de', 'ebay_fr', 'ovoko'])); }
    private function normalizeOrderImportChannels(array $requested): array { $normalized = []; foreach ($requested as $channel) { $normalized[] = in_array($channel, ['ebay_de', 'ebay_fr'], true) ? 'ebay' : $channel; } return array_values(array_unique($normalized)); }
    private function requestedEbayMarketChannels(array $requested): array { return array_values(array_intersect($requested, ['ebay_de', 'ebay_fr'])); }
    private function orderProvider(string $marketplace): string { return in_array($marketplace, ['ebay', 'ebay_de', 'ebay_fr'], true) ? 'ebay' : $marketplace; }
    private function accountCodes(string $marketplace): array { return $marketplace === 'allegro' ? ['allegro_main', 'allegro'] : ($marketplace === 'ebay' ? ['ebay_de', 'ebay_fr', 'ebay'] : ($marketplace === 'ovoko' ? ['ovoko_main', 'ovoko'] : [$marketplace])); }
    private function accountForOrders(string $marketplace): ?MarketplaceAccount { $query = MarketplaceAccount::query()->whereIn('code', $this->accountCodes($marketplace))->where('api_enabled', true); if ($marketplace === 'ebay') { $accounts = $query->get()->sortBy(fn (MarketplaceAccount $account): int => array_search((string) $account->code, $this->accountCodes('ebay'), true) === false ? 999 : (int) array_search((string) $account->code, $this->accountCodes('ebay'), true))->values(); return $accounts->first(fn (MarketplaceAccount $account): bool => str_contains(implode(' ', $this->safeScopes(is_array($account->api_credentials) ? $account->api_credentials : [])), 'sell.fulfillment')) ?: $accounts->first(); } return $query->first(); }
    private function responseMarketplaceId(array $raw): ?string { return $raw['marketplaceId'] ?? $raw['marketplace_id'] ?? $raw['orderMarketplaceId'] ?? null; }
    private function dedupeFetchedOrders(string $marketplace, array $orders, array &$result): array { if ($marketplace !== 'ebay') return $orders; $seen = []; $deduped = []; foreach ($orders as $order) { $id = (string) ($order['id'] ?? $order['orderId'] ?? $order['order_id'] ?? ''); $key = 'ebay|'.$id; if ($id !== '' && isset($seen[$key])) { $result['warnings'][] = ['marketplace' => 'ebay', 'code' => 'duplicate_across_channels', 'duplicate_across_channels' => true, 'dedupe_key' => $key, 'marketplace_order_id' => $id]; continue; } if ($id !== '') $seen[$key] = true; $deduped[] = $order; } return $deduped; }
    private function orderQuery(string $marketplace, array $options, int $limit): array { $since = $options['since'] ?? ($options['date_from'] ?? null); $query = ['limit' => $limit]; if (filled($options['offset'] ?? null)) $query['offset'] = $options['offset']; if (filled($options['status'] ?? null)) $query['status'] = $options['status']; if ($marketplace === 'allegro' && filled($since)) $query['lineItems.boughtAt.gte'] = Carbon::parse($since, 'Europe/Warsaw')->utc()->toIso8601String(); if (in_array($marketplace, ['ebay', 'ebay_de', 'ebay_fr'], true) && filled($since)) $query['filter'] = 'creationdate:['.Carbon::parse($since, 'Europe/Warsaw')->utc()->format('Y-m-d\TH:i:s.000\Z').'..]'; return $query; }
    private function localStatus(string $status): string { return str_contains(strtolower($status), 'cancel') ? 'cancelled' : (str_contains(strtolower($status), 'complete') ? 'completed' : 'new'); }
    private function emptySummary(array $o, bool $dry): array { return ['ok'=>true,'marketplace'=>$o['marketplace'] ?? 'all','dry_run'=>$dry,'date_from'=>$o['since'] ?? ($o['date_from'] ?? null),'date_to'=>$o['date_to'] ?? null,'orders_fetched'=>0,'orders_created'=>0,'orders_updated'=>0,'orders_skipped'=>0,'items_created'=>0,'items_updated'=>0,'errors'=>[],'warnings'=>[],'marketplaces'=>[],'safety_flags'=>$this->flags($dry)]; }
    private function emptyMarketplaceSummary(string $m, array $o, bool $dry): array { $requested = $this->requestedChannels((string) ($o['marketplace'] ?? ($o['channels'] ?? 'all'))); return ['marketplace'=>$m,'dry_run'=>$dry,'date_from'=>$o['since'] ?? ($o['date_from'] ?? null),'date_to'=>$o['date_to'] ?? null,'requested_channels'=>$requested,'normalized_channels'=>$this->normalizeOrderImportChannels($requested),'orders_fetched'=>0,'orders_created'=>0,'orders_updated'=>0,'orders_skipped'=>0,'items_created'=>0,'items_updated'=>0,'errors'=>[],'warnings'=>[],'would_import'=>[],'safety_flags'=>$this->flags($dry)]; }
    private function flags(bool $dry): array { return ['read_only'=>$dry,'orders_changed'=>! $dry,'products_changed'=>false,'parts_changed'=>false,'offers_changed'=>false,'listings_changed'=>false,'stock_changed'=>false,'prices_changed'=>false,'mappings_changed'=>false,'allegro_write'=>false,'ovoko_write'=>false,'ebay_write'=>false]; }

    private function orderAuthDiagnostics(string $marketplace, MarketplaceAccount $account, string $endpointPath): array
    {
        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
        $settings = is_array($account->api_settings) ? $account->api_settings : [];

        return array_filter([
            'channel' => $marketplace,
            'account_code' => $account->code,
            'marketplace_id' => in_array($marketplace, ['ebay', 'ebay_de', 'ebay_fr'], true)
                ? (string) ($settings['marketplace_id'] ?? ($account->code === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE'))
                : null,
            'token_present' => filled($credentials['access_token'] ?? null),
            'token_expires_at' => $this->tokenExpiresAt($marketplace, $credentials),
            'refresh_attempted' => false,
            'refreshed' => false,
            'scopes' => in_array($marketplace, ['ebay', 'ebay_de', 'ebay_fr'], true) ? $this->safeScopes($credentials) : null,
            'endpoint' => $endpointPath,
        ], fn ($value) => $value !== null);
    }

    private function tokenExpiresAt(string $marketplace, array $credentials): ?string
    {
        if ($marketplace === 'allegro') {
            return $credentials['access_token_expires_at'] ?? $credentials['expires_at'] ?? null;
        }

        return $credentials['expires_at'] ?? $credentials['access_token_expires_at'] ?? null;
    }

    private function canRefreshEbayToken(array $credentials): bool
    {
        return filled($credentials['client_id'] ?? null) && filled($credentials['client_secret'] ?? null) && filled($credentials['refresh_token'] ?? null);
    }

    private function refreshEbayAccessToken(MarketplaceAccount $account, array $credentials): ?string
    {
        if (! $this->canRefreshEbayToken($credentials)) return null;

        $response = Http::asForm()->withBasicAuth((string) $credentials['client_id'], (string) $credentials['client_secret'])->acceptJson()->timeout(20)->post(EbayOAuthConfig::tokenUrl((string) $account->api_base_url), [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $credentials['refresh_token'],
            'scope' => (string) ($credentials['scopes'] ?? EbayOAuthConfig::scopeString()),
        ]);
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) || blank($payload['access_token'] ?? null)) return null;

        $updated = array_merge($credentials, [
            'access_token' => (string) $payload['access_token'],
            'expires_at' => EbayOAuthConfig::tokenExpiresAt($payload['expires_in'] ?? null),
            'token_type' => (string) ($payload['token_type'] ?? ($credentials['token_type'] ?? '')),
            'scopes' => $payload['scope'] ?? ($credentials['scopes'] ?? EbayOAuthConfig::scopeString()),
            'refreshed_at' => now()->toISOString(),
        ]);
        if (filled($payload['refresh_token'] ?? null)) $updated['refresh_token'] = (string) $payload['refresh_token'];
        $account->forceFill(['api_credentials' => $updated, 'last_connection_check_at' => now(), 'last_connection_status' => 'ok', 'last_connection_message' => 'eBay access token refreshed securely for read-only order sync.'])->save();

        return (string) $updated['access_token'];
    }

    private function safeScopes(array $credentials): array
    {
        $scopes = $credentials['scopes'] ?? $credentials['scope'] ?? [];
        if (is_string($scopes)) $scopes = preg_split('/\s+/', trim($scopes)) ?: [];
        return array_values(array_filter(array_map('strval', is_array($scopes) ? $scopes : [])));
    }

    private function ordersAuthErrorMessage(string $marketplace, int $status, array $credentials): string
    {
        if ($status !== 401 && $status !== 403) return 'Read-only order endpoint returned non-success status.';

        if ($marketplace === 'allegro') {
            return 'Read-only Allegro orders authorization failed. Reconnect the Allegro account with order read permission if token refresh did not resolve it.';
        }

        if (in_array($marketplace, ['ebay', 'ebay_de', 'ebay_fr'], true)) {
            $scopeText = implode(' ', $this->safeScopes($credentials));
            $hasFulfillmentScope = str_contains($scopeText, 'sell.fulfillment');

            return $hasFulfillmentScope
                ? 'Read-only eBay orders authorization failed after token refresh attempt.'
                : 'Read-only eBay orders authorization failed. Reconnect the eBay account with order read / sell.fulfillment.readonly scope.';
        }

        return 'Read-only order endpoint returned non-success status.';
    }
}

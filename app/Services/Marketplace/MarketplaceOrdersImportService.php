<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class MarketplaceOrdersImportService
{
    public const TEST_BATCH = 'marketplace_orders_ui_test';

    public function run(array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $marketplaces = $this->marketplaces((string) ($options['marketplace'] ?? 'all'));
        $summary = $this->emptySummary($options, $dryRun);

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
        try {
            $orders = $this->fetchOrders($marketplace, $options, $result);
            $result['orders_fetched'] = count($orders);
            foreach ($orders as $raw) {
                $normalized = $this->normalizeOrder($marketplace, $raw);
                if (($normalized['marketplace_order_id'] ?? '') === '') { $result['orders_skipped']++; continue; }
                if (($normalized['ordered_at'] ?? null) === null) {
                    $result['warnings'][] = ['marketplace' => $marketplace, 'code' => 'missing_ordered_at', 'marketplace_order_id' => $normalized['marketplace_order_id']];
                }
                if ($dryRun) { $result['would_import'][] = Arr::only($normalized, ['marketplace','marketplace_order_id','marketplace_status','ordered_at','buyer_name','total_amount','currency']); continue; }
                $this->upsertOrder($normalized, $raw, $result);
            }
        } catch (Throwable $e) {
            $result['errors'][] = ['marketplace' => $marketplace, 'message' => 'Marketplace orders read failed without exposing secrets.', 'exception' => $e::class];
        }
        return $result;
    }

    private function fetchOrders(string $marketplace, array $options, array &$result): array
    {
        $account = MarketplaceAccount::query()->whereIn('code', $this->accountCodes($marketplace))->first();
        if (! $account || ! $account->api_enabled || blank($account->api_base_url)) {
            $result['warnings'][] = 'Marketplace account API is not configured/enabled.';
            return [];
        }
        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
        $limit = max(1, min((int) ($options['limit'] ?? 50), 100));
        $query = array_filter(['limit' => $limit, 'offset' => $options['offset'] ?? null, 'date_from' => $options['date_from'] ?? null, 'date_to' => $options['date_to'] ?? null, 'status' => $options['status'] ?? null]);
        $base = rtrim((string) $account->api_base_url, '/');

        if ($marketplace === 'allegro') {
            $response = Http::withToken((string) ($credentials['access_token'] ?? ''))->accept('application/vnd.allegro.public.v1+json')->timeout(25)->get($base.'/order/checkout-forms', $query);
        } elseif ($marketplace === 'ebay') {
            $response = Http::withToken((string) ($credentials['access_token'] ?? ''))->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => (string) (($account->api_settings ?? [])['marketplace_id'] ?? 'EBAY_DE')])->acceptJson()->timeout(25)->get($base.'/sell/fulfillment/v1/order', $query);
        } else {
            $response = Http::asForm()->acceptJson()->timeout(25)->post($base.'/v2/get/orders?'.http_build_query($query), Arr::only($credentials, ['username', 'password', 'user_token']));
        }

        $payload = is_array($response->json()) ? $response->json() : [];
        $result['api_http_status'] = $response->status();
        if (! $response->successful()) {
            $result['errors'][] = ['marketplace' => $marketplace, 'http_status' => $response->status(), 'message' => 'Read-only order endpoint returned non-success status.'];
            return [];
        }
        return array_values(array_filter($payload['checkoutForms'] ?? $payload['orders'] ?? $payload['data'] ?? $payload['list'] ?? [], 'is_array'));
    }

    private function normalizeOrder(string $marketplace, array $raw): array
    {
        $buyer = $raw['buyer'] ?? $raw['buyerInfo'] ?? [];
        $delivery = $raw['delivery'] ?? $raw['fulfillmentStartInstructions'][0]['shippingStep'] ?? [];
        $address = $delivery['address'] ?? $raw['shippingAddress'] ?? [];
        $items = $raw['lineItems'] ?? $raw['line_items'] ?? $raw['items'] ?? [];
        $total = $raw['summary']['totalToPay'] ?? $raw['pricingSummary']['total'] ?? $raw['total'] ?? [];
        $shipping = $raw['summary']['delivery'] ?? $raw['pricingSummary']['deliveryCost'] ?? $raw['deliveryCost'] ?? [];
        return [
            'marketplace' => $marketplace,
            'marketplace_order_id' => (string) ($raw['id'] ?? $raw['orderId'] ?? $raw['order_id'] ?? ''),
            'marketplace_status' => (string) ($raw['status'] ?? $raw['orderFulfillmentStatus'] ?? ''),
            'ordered_at' => $this->orderedAt($marketplace, $raw, array_values(array_filter($items, 'is_array'))),
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
        ];
    }


    private function orderedAt(string $marketplace, array $raw, array $items): ?string
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

    private function upsertOrder(array $n, array $raw, array &$result): void
    {
        DB::transaction(function () use ($n, $raw, &$result): void {
            $order = Order::query()->firstOrNew(['marketplace' => $n['marketplace'], 'marketplace_order_id' => $n['marketplace_order_id']]);
            $created = ! $order->exists;
            $order->fill([
                'order_number' => $order->order_number ?: strtoupper($n['marketplace']).'-'.$n['marketplace_order_id'],
                'marketplace_status' => $n['marketplace_status'], 'ordered_at' => $n['ordered_at'], 'status' => $this->localStatus($n['marketplace_status']),
                'currency' => substr($n['currency'], 0, 3), 'subtotal' => max(0, $n['total_amount'] - $n['delivery_amount']), 'shipping_total' => $n['delivery_amount'], 'total' => $n['total_amount'],
                'payment_status' => $n['payment_status'], 'delivery_method' => $n['delivery_method'], 'customer_name' => $n['buyer_name'], 'email' => $n['buyer_email'] ?: 'marketplace-'.$n['marketplace_order_id'].'@example.invalid',
                'phone' => $n['buyer_phone'] ?: '-', 'address_line1' => $n['delivery_address'] ?: '-', 'postal_code' => $n['delivery_postcode'] ?: '-', 'city' => $n['delivery_city'] ?: '-', 'country' => substr($n['delivery_country'] ?: 'PL', 0, 2),
                'invoice_data' => $n['invoice_data'], 'raw_payload' => $raw, 'imported_at' => $order->imported_at ?: now(), 'test_import' => true, 'source_batch' => self::TEST_BATCH,
                'notes' => trim('TEST IMPORT marketplace order. '.(string) ($order->notes ?? '')),
            ])->save();
            $created ? $result['orders_created']++ : $result['orders_updated']++;
            foreach ($n['items'] as $idx => $item) $this->upsertItem($order, $item, $idx, $result);
        });
    }

    private function upsertItem(Order $order, array $raw, int $idx, array &$result): void
    {
        $id = (string) ($raw['id'] ?? $raw['lineItemId'] ?? $raw['item_id'] ?? $idx);
        $price = $raw['price'] ?? $raw['unitPrice'] ?? $raw['lineItemCost'] ?? [];
        $qty = (int) ($raw['quantity'] ?? $raw['quantityPurchased'] ?? 1);
        $unit = (float) ($price['amount'] ?? $price['value'] ?? $raw['unit_price'] ?? 0);
        $item = $order->items()->firstOrNew(['marketplace' => $order->marketplace, 'marketplace_order_id' => $order->marketplace_order_id, 'marketplace_item_id' => $id]);
        $created = ! $item->exists;
        $item->fill(['product_name' => (string) ($raw['offer']['name'] ?? $raw['title'] ?? $raw['legacyItemId'] ?? 'Marketplace item'), 'sku' => (string) ($raw['offer']['external']['id'] ?? $raw['sku'] ?? ''), 'offer_id' => (string) ($raw['offer']['id'] ?? $raw['legacyItemId'] ?? ''), 'external_product_id' => (string) ($raw['productId'] ?? ''), 'unit_price' => $unit, 'quantity' => max(1, $qty), 'line_total' => (float) ($raw['total_price'] ?? ($unit * max(1, $qty))), 'currency' => (string) ($price['currency'] ?? $order->currency), 'raw_payload' => $raw])->save();
        $created ? $result['items_created']++ : $result['items_updated']++;
    }

    private function marketplaces(string $marketplace): array { return $marketplace === 'all' ? ['allegro', 'ebay', 'ovoko'] : array_values(array_intersect([$marketplace], ['allegro', 'ebay', 'ovoko'])); }
    private function accountCodes(string $marketplace): array { return $marketplace === 'allegro' ? ['allegro_main'] : ($marketplace === 'ebay' ? ['ebay_de', 'ebay_fr'] : ['ovoko_main', 'ovoko']); }
    private function localStatus(string $status): string { return str_contains(strtolower($status), 'cancel') ? 'cancelled' : (str_contains(strtolower($status), 'complete') ? 'completed' : 'new'); }
    private function emptySummary(array $o, bool $dry): array { return ['ok'=>true,'marketplace'=>$o['marketplace'] ?? 'all','dry_run'=>$dry,'date_from'=>$o['date_from'] ?? null,'date_to'=>$o['date_to'] ?? null,'orders_fetched'=>0,'orders_created'=>0,'orders_updated'=>0,'orders_skipped'=>0,'items_created'=>0,'items_updated'=>0,'errors'=>[],'warnings'=>[],'marketplaces'=>[],'safety_flags'=>$this->flags($dry)]; }
    private function emptyMarketplaceSummary(string $m, array $o, bool $dry): array { return ['marketplace'=>$m,'dry_run'=>$dry,'date_from'=>$o['date_from'] ?? null,'date_to'=>$o['date_to'] ?? null,'orders_fetched'=>0,'orders_created'=>0,'orders_updated'=>0,'orders_skipped'=>0,'items_created'=>0,'items_updated'=>0,'errors'=>[],'warnings'=>[],'would_import'=>[],'safety_flags'=>$this->flags($dry)]; }
    private function flags(bool $dry): array { return ['read_only'=>$dry,'orders_changed'=>! $dry,'products_changed'=>false,'offers_changed'=>false,'mappings_changed'=>false,'parts_changed'=>false,'allegro_write'=>false,'ovoko_write'=>false,'ebay_write'=>false]; }
}

<?php

namespace App\Services\Marketplace\Api;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Marketplace\ApiIntegrationLogger;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EbayFulfillmentService
{
    public function sendTracking(Order $order, Shipment $shipment): array
    {
        if (! $this->isEbayOrder($order)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_ebay_order'];
        }

        $account = $this->accountFor($order);
        if (! $account || ! $account->api_enabled || blank($account->api_base_url)) {
            throw new RuntimeException('Brak aktywnej konfiguracji konta eBay dla wysyłki fulfillment.');
        }

        $payload = $this->payload($order, $shipment);
        $token = (string) data_get($account->api_credentials, 'access_token');
        if ($token === '') {
            throw new RuntimeException('Brak access_token eBay dla wysyłki fulfillment.');
        }

        $orderId = $this->externalOrderId($order);
        $startedAt = microtime(true);
        $response = Http::withToken($token)
            ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => (string) data_get($account->api_settings, 'marketplace_id', $this->marketplaceId($order))])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post(rtrim((string) $account->api_base_url, '/').'/sell/fulfillment/v1/order/'.rawurlencode($orderId).'/shipping_fulfillment', $payload);

        if ($response->status() !== 201) {
            $exception = new RuntimeException('eBay odrzucił tracking fulfillment (HTTP '.$response->status().'): '.($response->body() ?: 'brak treści odpowiedzi'));
            app(ApiIntegrationLogger::class)->error('ebay', 'createShippingFulfillment', $exception, [
                'order_id' => $order->id,
                'shipment_id' => $shipment->id,
                'http_status' => $response->status(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'external_id' => $orderId,
                'tracking_number' => $payload['trackingNumber'] ?? null,
                'request' => ['order_id' => $orderId, 'payload' => $payload],
                'response' => ['body' => $response->body()],
            ]);
            throw $exception;
        }

        app(ApiIntegrationLogger::class)->success('ebay', 'createShippingFulfillment', 'eBay shipping fulfillment created.', [
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'http_status' => 201,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'external_id' => $orderId,
            'tracking_number' => $payload['trackingNumber'] ?? null,
            'request' => ['order_id' => $orderId, 'payload' => $payload],
            'response' => ['location' => $response->header('Location')],
        ]);

        return ['ok' => true, 'http_status' => 201, 'location' => $response->header('Location'), 'payload' => $payload];
    }

    public function payload(Order $order, Shipment $shipment): array
    {
        $tracking = preg_replace('/[^A-Za-z0-9]/', '', (string) ($shipment->tracking_number ?: $shipment->carrier_shipment_id));
        if ($tracking === '') {
            throw new RuntimeException('Brak numeru tracking DHL do wysłania do eBay.');
        }

        return [
            'lineItems' => $this->lineItems($order),
            'shippedDate' => now()->toISOString(),
            'shippingCarrierCode' => 'DHL',
            'trackingNumber' => $tracking,
        ];
    }

    public function isEbayOrder(Order $order): bool
    {
        return str_starts_with(strtolower((string) $order->marketplace), 'ebay');
    }

    private function lineItems(Order $order): array
    {
        $raw = data_get($order->raw_payload, 'lineItems', []);
        $items = collect(is_array($raw) ? $raw : [])->map(fn ($item) => [
            'lineItemId' => (string) data_get($item, 'lineItemId', data_get($item, 'legacyItemId', '')),
            'quantity' => (int) data_get($item, 'quantity', 1),
        ])->filter(fn ($item) => $item['lineItemId'] !== '')->values()->all();

        if ($items !== []) return $items;

        $items = $order->items()->get()->map(fn ($item) => [
            'lineItemId' => (string) ($item->marketplace_item_id ?: data_get($item->raw_payload, 'lineItemId')),
            'quantity' => max(1, (int) $item->quantity),
        ])->filter(fn ($item) => $item['lineItemId'] !== '')->values()->all();

        if ($items === []) throw new RuntimeException('Brak lineItemId zamówienia eBay do payloadu fulfillment.');
        return $items;
    }

    private function accountFor(Order $order): ?MarketplaceAccount
    {
        return MarketplaceAccount::query()->where('code', $this->channel($order))->first()
            ?: MarketplaceAccount::query()->where('code', 'ebay_de')->first();
    }

    private function channel(Order $order): string
    {
        $marketplace = strtolower((string) $order->marketplace);
        return in_array($marketplace, ['ebay_de', 'ebay_fr'], true) ? $marketplace : 'ebay_de';
    }

    private function marketplaceId(Order $order): string
    {
        return $this->channel($order) === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE';
    }

    private function externalOrderId(Order $order): string
    {
        $id = (string) ($order->marketplace_order_id ?: data_get($order->raw_payload, 'orderId'));
        if ($id === '') throw new RuntimeException('Brak eBay orderId w zamówieniu.');
        return $id;
    }
}

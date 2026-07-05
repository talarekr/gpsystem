<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Marketplace\ApiIntegrationLogger;
use Illuminate\Support\Arr;
use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MarketplaceOrderFulfillmentSyncService
{
    public function dryRun(Order $order): array
    {
        return $this->handle($order, false);
    }

    public function apply(Order $order): array
    {
        return $this->handle($order, true);
    }

    private function handle(Order $order, bool $apply): array
    {
        $order->loadMissing('items', 'shipments');
        $shipment = $this->latestTrackedShipment($order);
        $meta = is_array($order->meta) ? $order->meta : [];
        $marketplace = strtolower((string) $order->marketplace);
        $tracking = $shipment ? $this->trackingNumber($shipment) : '';
        $carrier = $shipment ? $this->carrierCode($shipment) : null;
        $accountContext = $this->accountContext($order);
        $payload = $this->payload($order, $shipment, $carrier);
        $guards = $this->guards($order, $shipment, $tracking, $carrier, $accountContext, $meta, $apply);

        $base = [
            'ok' => $guards === [],
            'dry_run' => ! $apply,
            'apply' => $apply,
            'order_id' => $order->id,
            'marketplace' => $marketplace,
            'marketplace_order_id' => $this->externalOrderId($order),
            'shipment_id' => $shipment?->id,
            'tracking_number' => $tracking ?: null,
            'carrier' => $carrier,
            'account' => Arr::except($accountContext, ['account']),
            'payload' => $payload,
            'guards' => $guards,
            'safety_flags' => [
                'read_only' => ! $apply,
                'single_order' => true,
                'orders_changed' => $apply && $guards === [],
                'products_changed' => false,
                'parts_changed' => false,
                'offers_changed' => false,
                'listings_changed' => false,
                'stock_changed' => false,
                'prices_changed' => false,
                'marketplace_write' => $apply && $guards === [],
            ],
        ];

        app(ApiIntegrationLogger::class)->record([
            'integration' => $marketplace,
            'action' => 'marketplace_fulfillment_sync_'.($apply ? 'apply' : 'dry_run'),
            'status' => $guards === [] ? 'success' : 'error',
            'order_id' => $order->id,
            'shipment_id' => $shipment?->id,
            'message' => $apply ? 'Single-order marketplace fulfillment sync.' : 'Dry-run only; no marketplace write.',
            'tracking_number' => $tracking ?: null,
            'external_id' => $this->externalOrderId($order),
            'request' => Arr::except($base, ['account.account']),
        ]);

        if ($guards !== [] || ! $apply) return $base;

        $response = $marketplace === 'allegro'
            ? $this->sendAllegro($accountContext['account'], $order, $payload)
            : $this->sendEbay($accountContext['account'], $order, $payload, (string) $accountContext['marketplace_id']);

        $this->markSynced($order, $shipment, $accountContext, $response);

        return $base + ['response' => $response, 'ok' => true];
    }

    private function guards(Order $order, ?Shipment $shipment, string $tracking, ?string $carrier, array $accountContext, array $meta, bool $apply): array
    {
        $guards = [];
        if (! config('marketplace_fulfillment.sync_enabled', false)) $guards[] = 'GPS_MARKETPLACE_FULFILLMENT_SYNC_ENABLED=false';
        if ($apply && ! config('marketplace_fulfillment.write_enabled', false)) $guards[] = 'GPS_MARKETPLACE_FULFILLMENT_WRITE_ENABLED=false';
        if (! in_array(strtolower((string) $order->marketplace), ['allegro', 'ebay', 'ebay_de', 'ebay_fr'], true)) $guards[] = 'order_not_allegro_or_ebay';
        if ((string) $order->status !== 'shipped') $guards[] = 'local_status_not_shipped';
        if (! $shipment || $tracking === '') $guards[] = 'missing_tracking_number';
        if ($carrier === null) $guards[] = 'unrecognized_carrier';
        if (($accountContext['ok'] ?? false) !== true) $guards[] = (string) ($accountContext['reason'] ?? 'marketplace_account_unresolved');
        if (($meta['marketplace_fulfillment_tracking_number'] ?? null) === $tracking && ($meta['marketplace_fulfillment_status'] ?? null) === 'synced') $guards[] = 'tracking_already_synced';
        if ($this->externalOrderId($order) === '') $guards[] = 'missing_marketplace_order_id';
        if (str_starts_with(strtolower((string) $order->marketplace), 'ebay') && $this->ebayLineItems($order) === []) $guards[] = 'missing_ebay_line_items';
        return array_values(array_unique($guards));
    }

    private function latestTrackedShipment(Order $order): ?Shipment
    {
        return $order->shipments->filter(fn (Shipment $s) => $this->trackingNumber($s) !== '')->sortByDesc('id')->first();
    }

    private function payload(Order $order, ?Shipment $shipment, ?string $carrier): array
    {
        if (! $shipment || ! $carrier) return [];
        return strtolower((string) $order->marketplace) === 'allegro'
            ? ['shipment' => ['carrierId' => $carrier, 'waybill' => $this->trackingNumber($shipment), 'lineItems' => $this->allegroLineItems($order)], 'fulfillment' => ['status' => 'SENT']]
            : ['lineItems' => $this->ebayLineItems($order), 'shippedDate' => now()->toISOString(), 'shippingCarrierCode' => $carrier, 'trackingNumber' => $this->trackingNumber($shipment)];
    }

    private function accountContext(Order $order): array
    {
        $marketplace = strtolower((string) $order->marketplace);
        if ($marketplace === 'allegro') {
            $account = MarketplaceAccount::query()->whereIn('code', ['allegro_main', 'allegro'])->where('api_enabled', true)->first();
            return $account ? ['ok' => true, 'account' => $account, 'account_code' => $account->code, 'marketplace_id' => null] : ['ok' => false, 'reason' => 'allegro_account_unresolved'];
        }
        if (! str_starts_with($marketplace, 'ebay')) return ['ok' => false, 'reason' => 'unsupported_marketplace'];
        $source = $this->ebayMarketplaceId($order);
        if (! in_array($source, ['EBAY_DE', 'EBAY_FR'], true)) return ['ok' => false, 'reason' => 'ebay_marketplace_id_unresolved'];
        $code = $source === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de';
        $account = MarketplaceAccount::query()->where('code', $code)->where('api_enabled', true)->first();
        return $account ? ['ok' => true, 'account' => $account, 'account_code' => $code, 'marketplace_id' => $source] : ['ok' => false, 'reason' => 'ebay_account_'.$code.'_unresolved', 'account_code' => $code, 'marketplace_id' => $source];
    }

    private function ebayMarketplaceId(Order $order): ?string
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $candidates = [$meta['source_marketplace_id'] ?? null, $meta['marketplace_id'] ?? null, data_get($order->raw_payload, 'marketplaceId'), data_get($order->raw_payload, 'orderMarketplaceId'), data_get($order->raw_payload, 'lineItems.0.listingMarketplaceId')];
        foreach ($candidates as $candidate) { $candidate = strtoupper((string) $candidate); if (in_array($candidate, ['EBAY_DE', 'EBAY_FR'], true)) return $candidate; }
        return null;
    }

    private function sendEbay(MarketplaceAccount $account, Order $order, array $payload, string $marketplaceId): array
    {
        $response = Http::withToken((string) data_get($account->api_credentials, 'access_token'))->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId])->acceptJson()->asJson()->timeout(20)->post(rtrim((string) $account->api_base_url, '/').'/sell/fulfillment/v1/order/'.rawurlencode($this->externalOrderId($order)).'/shipping_fulfillment', $payload);
        if ($response->status() !== 201) throw new RuntimeException('eBay fulfillment write failed HTTP '.$response->status());
        return ['http_status' => 201, 'fulfillment_id' => basename((string) $response->header('Location')), 'location' => $response->header('Location')];
    }

    private function sendAllegro(MarketplaceAccount $account, Order $order, array $payload): array
    {
        $base = rtrim((string) $account->api_base_url, '/');
        $token = (string) data_get($account->api_credentials, 'access_token');
        $shipment = AllegroUserAgent::request()->withToken($token)->accept('application/vnd.allegro.public.v1+json')->contentType('application/vnd.allegro.public.v1+json')->timeout(20)->post($base.'/order/checkout-forms/'.rawurlencode($this->externalOrderId($order)).'/shipments', $payload['shipment']);
        if (! $shipment->successful()) throw new RuntimeException('Allegro shipment write failed HTTP '.$shipment->status());
        $fulfillment = AllegroUserAgent::request()->withToken($token)->accept('application/vnd.allegro.public.v1+json')->contentType('application/vnd.allegro.public.v1+json')->timeout(20)->put($base.'/order/checkout-forms/'.rawurlencode($this->externalOrderId($order)).'/fulfillment', $payload['fulfillment']);
        if (! $fulfillment->successful()) throw new RuntimeException('Allegro fulfillment write failed HTTP '.$fulfillment->status());
        return ['shipment_http_status' => $shipment->status(), 'fulfillment_http_status' => $fulfillment->status(), 'fulfillment_id' => data_get($shipment->json(), 'id')];
    }

    private function markSynced(Order $order, Shipment $shipment, array $accountContext, array $response): void
    {
        $order->forceFill(['meta' => array_merge(is_array($order->meta) ? $order->meta : [], [
            'marketplace_fulfillment_synced_at' => now()->toISOString(),
            'marketplace_fulfillment_tracking_number' => $this->trackingNumber($shipment),
            'marketplace_fulfillment_carrier' => $this->carrierCode($shipment),
            'marketplace_fulfillment_external_id' => $response['fulfillment_id'] ?? $response['location'] ?? null,
            'marketplace_fulfillment_account_code' => $accountContext['account_code'] ?? null,
            'marketplace_fulfillment_marketplace_id' => $accountContext['marketplace_id'] ?? null,
            'marketplace_fulfillment_status' => 'synced',
            'marketplace_fulfillment_last_error' => null,
        ])])->save();
    }

    private function trackingNumber(Shipment $shipment): string { return preg_replace('/[^A-Za-z0-9]/', '', (string) ($shipment->tracking_number ?: $shipment->carrier_shipment_id)); }
    private function carrierCode(Shipment $shipment): ?string { return match (strtolower((string) $shipment->carrier)) { 'dhl' => 'DHL', 'dpd' => 'DPD', default => null }; }
    private function externalOrderId(Order $order): string { return (string) ($order->marketplace_order_id ?: data_get($order->raw_payload, 'orderId') ?: data_get($order->raw_payload, 'id')); }
    private function ebayLineItems(Order $order): array { return $order->items->map(fn ($i) => ['lineItemId' => (string) ($i->marketplace_item_id ?: data_get($i->raw_payload, 'lineItemId')), 'quantity' => max(1, (int) $i->quantity)])->filter(fn ($i) => $i['lineItemId'] !== '')->values()->all(); }
    private function allegroLineItems(Order $order): array { return $order->items->map(fn ($i) => ['id' => (string) ($i->marketplace_item_id ?: data_get($i->raw_payload, 'id'))])->filter(fn ($i) => $i['id'] !== '')->values()->all(); }
}

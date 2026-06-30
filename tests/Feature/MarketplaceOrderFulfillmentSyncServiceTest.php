<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Services\Marketplace\MarketplaceOrderFulfillmentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceOrderFulfillmentSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_ebay_builds_payload_without_sending(): void
    {
        config(['marketplace_fulfillment.sync_enabled' => true, 'marketplace_fulfillment.write_enabled' => false]);
        Http::fake();
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'code' => 'ebay_fr', 'name' => 'eBay FR', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token'], 'api_settings' => ['marketplace_id' => 'EBAY_FR']]);
        $order = $this->order('ebay', ['marketplaceId' => 'EBAY_FR']);
        $this->item($order, 'line-1');
        $this->shipment($order);

        $result = app(MarketplaceOrderFulfillmentSyncService::class)->dryRun($order);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('EBAY_FR', $result['account']['marketplace_id']);
        $this->assertSame('DHL123', $result['payload']['trackingNumber']);
        Http::assertNothingSent();
    }

    public function test_dry_run_allegro_builds_payload_without_sending(): void
    {
        config(['marketplace_fulfillment.sync_enabled' => true]);
        Http::fake();
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.test', 'api_credentials' => ['access_token' => 'token']]);
        $order = $this->order('allegro', ['id' => 'checkout-1']);
        $this->item($order, 'allegro-line-1');
        $this->shipment($order);

        $result = app(MarketplaceOrderFulfillmentSyncService::class)->dryRun($order);

        $this->assertTrue($result['ok']);
        $this->assertSame('SENT', $result['payload']['fulfillment']['status']);
        $this->assertSame('DHL123', $result['payload']['shipment']['waybill']);
        Http::assertNothingSent();
    }

    public function test_apply_env_false_blocks_write(): void
    {
        config(['marketplace_fulfillment.sync_enabled' => true, 'marketplace_fulfillment.write_enabled' => false]);
        Http::fake();
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'code' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token']]);
        $order = $this->order('ebay', ['marketplaceId' => 'EBAY_DE']);
        $this->item($order, 'line-1');
        $this->shipment($order);

        $result = app(MarketplaceOrderFulfillmentSyncService::class)->apply($order);

        $this->assertFalse($result['ok']);
        $this->assertContains('GPS_MARKETPLACE_FULFILLMENT_WRITE_ENABLED=false', $result['guards']);
        Http::assertNothingSent();
    }

    public function test_missing_tracking_blocks_fulfillment(): void
    {
        config(['marketplace_fulfillment.sync_enabled' => true]);
        $order = $this->order('allegro', ['id' => 'checkout-1']);

        $result = app(MarketplaceOrderFulfillmentSyncService::class)->dryRun($order);

        $this->assertFalse($result['ok']);
        $this->assertContains('missing_tracking_number', $result['guards']);
    }

    public function test_unresolved_ebay_marketplace_blocks_fulfillment(): void
    {
        config(['marketplace_fulfillment.sync_enabled' => true]);
        $order = $this->order('ebay', []);
        $this->item($order, 'line-1');
        $this->shipment($order);

        $result = app(MarketplaceOrderFulfillmentSyncService::class)->dryRun($order);

        $this->assertFalse($result['ok']);
        $this->assertContains('ebay_marketplace_id_unresolved', $result['guards']);
    }

    public function test_same_tracking_is_idempotent(): void
    {
        config(['marketplace_fulfillment.sync_enabled' => true]);
        $order = $this->order('allegro', ['id' => 'checkout-1'], ['marketplace_fulfillment_tracking_number' => 'DHL123', 'marketplace_fulfillment_status' => 'synced']);
        $this->shipment($order);

        $result = app(MarketplaceOrderFulfillmentSyncService::class)->dryRun($order);

        $this->assertFalse($result['ok']);
        $this->assertContains('tracking_already_synced', $result['guards']);
    }

    private function order(string $marketplace, array $rawPayload, array $meta = []): Order
    {
        return Order::query()->create(['order_number' => strtoupper($marketplace).'-1', 'marketplace' => $marketplace, 'marketplace_order_id' => $rawPayload['id'] ?? $rawPayload['orderId'] ?? 'order-1', 'status' => 'shipped', 'currency' => 'EUR', 'subtotal' => 1, 'shipping_total' => 1, 'total' => 2, 'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'phone' => '123', 'address_line1' => 'Street 1', 'postal_code' => '12345', 'city' => 'City', 'country' => 'DE', 'raw_payload' => $rawPayload, 'meta' => $meta]);
    }

    private function item(Order $order, string $lineItemId): OrderItem
    {
        return OrderItem::query()->create(['order_id' => $order->id, 'marketplace' => $order->marketplace, 'marketplace_order_id' => $order->marketplace_order_id, 'marketplace_item_id' => $lineItemId, 'product_name' => 'Part', 'unit_price' => 1, 'quantity' => 1, 'line_total' => 1, 'currency' => $order->currency]);
    }

    private function shipment(Order $order): Shipment
    {
        return Shipment::query()->create(['order_id' => $order->id, 'carrier' => 'dhl', 'shipment_status' => 'label_created', 'tracking_number' => 'DHL123']);
    }
}

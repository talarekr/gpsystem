<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Services\Admin\LocalOrderStatusUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceOrderStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_allegro_processing_updates_fulfillment_processing(): void
    {
        Http::fake(['https://allegro.test/*' => Http::response([], 204)]);
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $order = Order::query()->create(['order_number' => 'A1', 'marketplace' => 'allegro', 'marketplace_order_id' => 'cf-1', 'status' => 'new']);

        app(LocalOrderStatusUpdater::class)->update($order, 'processing');

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_contains($request->url(), '/order/checkout-forms/cf-1/fulfillment') && $request['status'] === 'PROCESSING');
        $this->assertDatabaseHas('marketplace_sync_logs', ['order_id' => $order->id, 'marketplace' => 'allegro', 'action' => 'order_status_sync', 'status' => 'success', 'external_id' => 'cf-1']);
    }

    public function test_ebay_shipped_creates_shipping_fulfillment_only_for_ebay(): void
    {
        Http::fake(['https://ebay.test/*' => Http::response(['fulfillmentId' => 'f-1'], 201)]);
        MarketplaceAccount::query()->create(['marketplace' => 'ebay_de', 'code' => 'ebay_de', 'name' => 'eBay DE', 'api_enabled' => true, 'api_base_url' => 'https://ebay.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $order = Order::query()->create(['order_number' => 'E1', 'marketplace' => 'ebay_de', 'marketplace_order_id' => 'ebay-order-1', 'status' => 'new']);
        OrderItem::query()->create(['order_id' => $order->id, 'marketplace' => 'ebay', 'marketplace_order_id' => 'ebay-order-1', 'marketplace_item_id' => 'line-1', 'product_name' => 'Part', 'quantity' => 1]);
        Shipment::query()->create(['order_id' => $order->id, 'carrier' => 'DHL', 'tracking_number' => 'TRACK1', 'shipment_status' => 'created']);

        app(LocalOrderStatusUpdater::class)->update($order, 'shipped');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/sell/fulfillment/v1/order/ebay-order-1/shipping_fulfillment') && $request['lineItems'][0]['lineItemId'] === 'line-1' && $request['trackingNumber'] === 'TRACK1');
        $this->assertDatabaseHas('marketplace_sync_logs', ['order_id' => $order->id, 'marketplace' => 'ebay', 'action' => 'ebay_create_shipping_fulfillment', 'status' => 'success']);
    }

    public function test_local_order_and_unsupported_status_are_logged_without_api_call(): void
    {
        Http::fake();
        $local = Order::query()->create(['order_number' => 'L1', 'marketplace' => 'shop', 'status' => 'new']);
        app(LocalOrderStatusUpdater::class)->update($local, 'processing');
        $ovoko = Order::query()->create(['order_number' => 'O1', 'marketplace' => 'ovoko', 'marketplace_order_id' => 'ovoko-1', 'status' => 'new']);
        app(LocalOrderStatusUpdater::class)->update($ovoko, 'processing');

        Http::assertNothingSent();
        $this->assertDatabaseHas('marketplace_sync_logs', ['order_id' => $local->id, 'status' => 'skipped', 'message' => 'local_or_unsupported_marketplace']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['order_id' => $ovoko->id, 'status' => 'skipped', 'message' => 'ovoko_order_status_endpoint_not_confirmed_in_rrr_docs']);
    }

    public function test_duplicate_success_status_is_not_sent_again(): void
    {
        Http::fake(['https://allegro.test/*' => Http::response([], 204)]);
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $order = Order::query()->create(['order_number' => 'A2', 'marketplace' => 'allegro', 'marketplace_order_id' => 'cf-2', 'status' => 'new']);
        MarketplaceSyncLog::query()->create(['marketplace' => 'allegro', 'order_id' => $order->id, 'action' => 'order_status_sync', 'status' => 'success', 'external_id' => 'cf-2', 'payload' => ['new_local_status' => 'processing', 'target_marketplace_status' => 'PROCESSING'], 'created_at' => now()]);

        app(LocalOrderStatusUpdater::class)->update($order, 'processing');

        Http::assertNothingSent();
        $this->assertDatabaseHas('marketplace_sync_logs', ['order_id' => $order->id, 'status' => 'skipped', 'message' => 'already_synced']);
    }
}

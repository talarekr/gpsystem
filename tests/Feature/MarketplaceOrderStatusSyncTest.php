<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Admin\LocalOrderStatusUpdater;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
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


    public function test_allegro_supported_fulfillment_statuses_are_sent_to_allegro_only(): void
    {
        Http::fake(['https://allegro.test/*' => Http::response([], 204)]);
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);

        foreach ([
            'new' => 'NEW',
            'processing' => 'PROCESSING',
            'ready_to_ship' => 'READY_FOR_SHIPMENT',
            'ready_for_pickup' => 'READY_FOR_PICKUP',
            'shipped' => 'SENT',
            'picked_up' => 'PICKED_UP',
        ] as $localStatus => $allegroStatus) {
            $order = Order::query()->create(['order_number' => 'A-'.$localStatus, 'marketplace' => 'allegro', 'marketplace_order_id' => 'cf-'.$localStatus, 'status' => 'processing']);

            app(LocalOrderStatusUpdater::class)->update($order, $localStatus);

            Http::assertSent(fn ($request) => $request->method() === 'PUT'
                && str_contains($request->url(), '/order/checkout-forms/cf-'.$localStatus.'/fulfillment')
                && $request['status'] === $allegroStatus);
        }

        Http::assertSentCount(6);
    }

    public function test_allegro_unsupported_front_statuses_are_logged_with_mapping_context(): void
    {
        Http::fake();
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $order = Order::query()->create(['order_number' => 'A-HOLD', 'marketplace' => 'allegro', 'marketplace_order_id' => 'cf-hold', 'status' => 'new']);

        app(LocalOrderStatusUpdater::class)->update($order, 'on_hold');

        Http::assertNothingSent();
        $log = MarketplaceSyncLog::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame('skipped', $log->status);
        $this->assertSame('unsupported_allegro_status', $log->message);
        $this->assertSame('on_hold', $log->payload['request_summary']['local_status']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::CODE_VERSION, $log->payload['order_status_sync_code_version']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::CODE_VERSION, $log->payload['request_summary']['order_status_sync_code_version']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::CODE_VERSION, $log->payload['response_summary']['order_status_sync_code_version']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::SYNC_WRITER, $log->payload['sync_writer']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::SYNC_WRITER, $log->payload['request_summary']['sync_writer']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::SYNC_WRITER, $log->payload['response_summary']['sync_writer']);
        $this->assertSame('unsupported_allegro_status', $log->payload['response_summary']['skipped_reason']);
    }

    public function test_log_39295_allegro_ui_w_realizacji_maps_to_processing(): void
    {
        Http::fake(['https://allegro.test/*' => Http::response([], 204)]);
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $order = Order::query()->create(['id' => 135, 'order_number' => '135', 'marketplace' => 'allegro', 'marketplace_order_id' => 'f2f054f0-7866-11f1-b5bc-398519c00320', 'status' => 'new']);

        app(LocalOrderStatusUpdater::class)->update($order, 'processing');

        Http::assertSent(fn ($request) => $request['status'] === 'PROCESSING'
            && str_contains($request->url(), '/order/checkout-forms/f2f054f0-7866-11f1-b5bc-398519c00320/fulfillment'));
        $this->assertDatabaseMissing('marketplace_sync_logs', ['order_id' => 135, 'status' => 'skipped', 'message' => 'unsupported_status_for_marketplace']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['order_id' => 135, 'status' => 'success', 'message' => 'Allegro order fulfillment status updated.']);
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

    public function test_admin_new_orders_dropdown_path_sends_allegro_processing_and_logs_debug_context(): void
    {
        $this->actingAsAdminUser();
        Http::fake(['https://allegro.test/*' => Http::response([], 204)]);
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $order = Order::query()->create(['id' => 135, 'order_number' => '135', 'marketplace' => 'allegro', 'marketplace_order_id' => 'f2f054f0-7866-11f1-b5bc-398519c00320', 'status' => 'new']);

        \Livewire\Livewire::withQueryParams(['status' => 'new'])
            ->test(\App\Filament\Resources\OrderResource\Pages\ListOrders::class)
            ->call('updateOrderStatus', $order->id, 'processing');

        $order->refresh();
        $this->assertSame('processing', $order->status);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/order/checkout-forms/f2f054f0-7866-11f1-b5bc-398519c00320/fulfillment')
            && $request['status'] === 'PROCESSING');

        $log = MarketplaceSyncLog::query()->where('order_id', 135)->latest('id')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertSame('processing', $log->payload['local_status_raw_value']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::CODE_VERSION, $log->payload['order_status_sync_code_version']);
        $this->assertSame('W REALIZACJI', $log->payload['local_status_ui_label']);
        $this->assertSame('new', $log->payload['previous_local_status']);
        $this->assertSame('allegro', $log->payload['marketplace']);
        $this->assertSame('f2f054f0-7866-11f1-b5bc-398519c00320', $log->payload['marketplace_order_id']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::class, $log->payload['mapper_class']);
        $this->assertSame('plan', $log->payload['mapper_method']);
        $this->assertSame('PROCESSING', $log->payload['available_map']['processing']);
        $this->assertSame('PROCESSING', $log->payload['target_marketplace_status']);
        $this->assertSame('allegro_fulfillment_status', $log->payload['mapper_branch']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::SYNC_WRITER, $log->payload['sync_writer']);
    }


    public function test_order_135_processing_uses_fresh_model_and_allegro_mapper_context(): void
    {
        Http::fake(['https://allegro.test/*' => Http::response([], 204)]);
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $staleOrder = Order::query()->create(['id' => 135, 'order_number' => '135', 'marketplace' => 'Allegro', 'marketplace_order_id' => 'f2f054f0-7866-11f1-b5bc-398519c00320', 'status' => 'new']);

        Order::query()->whereKey(135)->update(['status' => 'processing']);
        app(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::class)->sync($staleOrder, 'new');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/order/checkout-forms/f2f054f0-7866-11f1-b5bc-398519c00320/fulfillment')
            && $request['status'] === 'PROCESSING');
        $this->assertDatabaseMissing('marketplace_sync_logs', ['order_id' => 135, 'status' => 'skipped', 'message' => 'unsupported_status_for_marketplace']);

        $log = MarketplaceSyncLog::query()->where('order_id', 135)->latest('id')->firstOrFail();
        $this->assertSame('order_status_sync', $log->action);
        $this->assertSame('success', $log->status);
        $this->assertSame('processing', $log->payload['local_status_raw_value']);
        $this->assertSame('Allegro', $log->payload['marketplace_raw_value']);
        $this->assertSame('allegro', $log->payload['normalized_marketplace']);
        $this->assertSame('PROCESSING', $log->payload['target_marketplace_status']);
        $this->assertSame('allegro_fulfillment_status', $log->payload['mapper_branch']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::CODE_VERSION, $log->payload['code_version']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::SYNC_WRITER, $log->payload['sync_writer']);
    }

    public function test_api_integration_logger_keeps_order_status_sync_markers_for_skipped_logs(): void
    {
        app(\App\Services\Marketplace\ApiIntegrationLogger::class)->record([
            'integration' => 'allegro',
            'action' => 'order_status_sync',
            'status' => 'skipped',
            'message' => 'unsupported_status_for_marketplace',
            'order_id' => 135,
            'external_id' => 'f2f054f0-7866-11f1-b5bc-398519c00320',
            'request' => ['local_status_raw_value' => 'processing', 'mapper_branch' => 'legacy_fallback_probe'],
            'response' => ['target_marketplace_status' => 'PROCESSING'],
        ]);

        $log = MarketplaceSyncLog::query()->latest('id')->firstOrFail();

        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::CODE_VERSION, $log->payload['meta']['order_status_sync_code_version']);
        $this->assertSame(\App\Services\Marketplace\ApiIntegrationLogger::class.'::record', $log->payload['meta']['sync_writer']);
        $this->assertSame(\App\Services\Marketplace\OrderStatusMarketplaceSyncService::CODE_VERSION, $log->payload['request']['order_status_sync_code_version']);
        $this->assertSame('processing', $log->payload['request']['local_status_raw_value']);
        $this->assertSame('PROCESSING', $log->payload['response']['target_marketplace_status']);
    }

    public function test_app_deploy_debug_can_include_raw_order_status_logs(): void
    {
        $order = Order::query()->create(['id' => 135, 'order_number' => '135', 'marketplace' => 'allegro', 'marketplace_order_id' => 'cf-135', 'status' => 'processing']);
        MarketplaceSyncLog::query()->create([
            'marketplace' => 'allegro',
            'order_id' => $order->id,
            'action' => 'order_status_sync',
            'status' => 'skipped',
            'message' => 'unsupported_status_for_marketplace',
            'external_id' => 'cf-135',
            'payload' => [
                'order_status_sync_code_version' => 'probe-version',
                'sync_writer' => 'probe-writer',
                'request_summary' => ['local_status_raw_value' => 'processing'],
            ],
            'created_at' => now(),
        ]);

        $response = $this->getJson('/tools/app-deploy-debug?token=gps_images_import_2026&order_id=135&include_order_status_logs=1');

        $response->assertOk()
            ->assertJsonPath('order_status_logs_included', true)
            ->assertJsonPath('order_status_logs.0.message', 'unsupported_status_for_marketplace')
            ->assertJsonPath('order_status_logs.0.payload_raw.order_status_sync_code_version', 'probe-version')
            ->assertJsonPath('order_status_logs.0.payload_raw.sync_writer', 'probe-writer')
            ->assertJsonPath('order_status_logs.0.payload_raw.request_summary.local_status_raw_value', 'processing');
    }


    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Owner Admin',
            'email' => 'owner'.uniqid().'@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}

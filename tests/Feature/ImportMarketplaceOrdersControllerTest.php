<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Marketplace\MarketplaceOrdersImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportMarketplaceOrdersControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_allegro_dry_run_maps_ordered_at_from_earliest_line_item_bought_at_without_writes(): void
    {
        Http::fake([
            'allegro.example.test/order/checkout-forms*' => Http::response([
                'checkoutForms' => [[
                    'id' => 'AL-1',
                    'status' => 'READY_FOR_PROCESSING',
                    'updatedAt' => '2026-06-12T10:00:00.000Z',
                    'buyer' => ['login' => 'buyer'],
                    'summary' => ['totalToPay' => ['amount' => '100.00', 'currency' => 'PLN']],
                    'lineItems' => [
                        ['id' => 'LI-2', 'boughtAt' => '2026-06-11T12:00:00.000Z', 'quantity' => 1, 'price' => ['amount' => '50.00', 'currency' => 'PLN']],
                        ['id' => 'LI-1', 'boughtAt' => '2026-06-10T12:00:00.000Z', 'quantity' => 1, 'price' => ['amount' => '50.00', 'currency' => 'PLN']],
                    ],
                ]],
            ], 200),
        ]);

        $this->createAllegroAccount();

        $response = $this->getJson('/tools/import-marketplace-orders?token=gps_images_import_2026&marketplace=allegro&dry_run=1&date_from=2026-06-01&limit=20&include_debug=1');

        $response->assertOk()
            ->assertJsonPath('marketplaces.allegro.api_http_status', 200)
            ->assertJsonPath('marketplaces.allegro.orders_fetched', 1)
            ->assertJsonPath('marketplaces.allegro.would_import.0.ordered_at', '2026-06-10 14:00:00')
            ->assertJsonPath('marketplaces.allegro.would_import.0.ordered_at_utc', '2026-06-10T12:00:00.000Z')
            ->assertJsonPath('marketplaces.allegro.would_import.0.ordered_at_local', '2026-06-10 14:00:00')
            ->assertJsonPath('marketplaces.allegro.would_import.0.timezone', 'Europe/Warsaw')
            ->assertJsonPath('marketplaces.allegro.safety_flags.read_only', true)
            ->assertJsonPath('marketplaces.allegro.safety_flags.orders_changed', false)
            ->assertJsonPath('marketplaces.allegro.safety_flags.allegro_write', false);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_allegro_dry_run_warns_when_ordered_at_is_missing(): void
    {
        Http::fake([
            'allegro.example.test/order/checkout-forms*' => Http::response([
                'checkoutForms' => [[
                    'id' => 'AL-MISSING',
                    'status' => 'READY_FOR_PROCESSING',
                    'buyer' => ['login' => 'buyer'],
                    'summary' => ['totalToPay' => ['amount' => '100.00', 'currency' => 'PLN']],
                    'lineItems' => [['id' => 'LI-1', 'quantity' => 1, 'price' => ['amount' => '100.00', 'currency' => 'PLN']]],
                ]],
            ], 200),
        ]);

        $this->createAllegroAccount();

        $response = $this->getJson('/tools/import-marketplace-orders?token=gps_images_import_2026&marketplace=allegro&dry_run=1&date_from=2026-06-01&limit=20&include_debug=1');

        $response->assertOk()
            ->assertJsonPath('marketplaces.allegro.api_http_status', 200)
            ->assertJsonPath('marketplaces.allegro.orders_fetched', 1)
            ->assertJsonPath('marketplaces.allegro.would_import.0.ordered_at', null)
            ->assertJsonPath('marketplaces.allegro.warnings.0.code', 'missing_ordered_at')
            ->assertJsonPath('marketplaces.allegro.warnings.0.marketplace_order_id', 'AL-MISSING')
            ->assertJsonPath('marketplaces.allegro.safety_flags.read_only', true)
            ->assertJsonPath('marketplaces.allegro.safety_flags.orders_changed', false)
            ->assertJsonPath('marketplaces.allegro.safety_flags.allegro_write', false);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_ovoko_dry_run_prefers_nested_seller_amounts_without_array_casting(): void
    {
        Http::fake([
            'ovoko.example.test/v2/get/orders/2026-06-01/2026-06-26' => Http::response([
                'status_code' => 'R200',
                'msg' => 'OK',
                'list' => [[
                    'order_id' => '8365433',
                    'order_date' => '2026-06-10 12:00:00',
                    'client_name' => 'Buyer One',
                    'total_price' => [
                        'seller' => ['amount' => '954.72', 'currency' => 'PLN'],
                        'buyer' => ['amount' => '224.64', 'currency' => 'EUR'],
                    ],
                    'shipping_price' => [
                        'seller' => ['amount' => '454.71', 'currency' => 'PLN'],
                        'buyer' => ['amount' => '106.99', 'currency' => 'EUR'],
                    ],
                    'item_list' => [[
                        'item_id' => 'ITEM-1',
                        'title' => 'Part one',
                        'quantity' => 1,
                        'sell_price' => [
                            'seller' => ['amount' => '500.01', 'currency' => 'PLN'],
                            'buyer' => ['amount' => '117.65', 'currency' => 'EUR'],
                        ],
                    ]],
                ], [
                    'order_id' => 'fe002d80-5d99-11f1-9010-eb54f1868795',
                    'order_date' => '2026-06-11 12:00:00',
                    'client_name' => 'Buyer Two',
                    'total_price' => [
                        'seller' => ['amount' => '125.00', 'currency' => 'PLN'],
                        'buyer' => ['amount' => '125.00', 'currency' => 'PLN'],
                    ],
                    'item_list' => [[
                        'item_id' => 'ITEM-2',
                        'title' => 'Part two',
                        'quantity' => 1,
                        'sell_price' => ['seller' => ['amount' => '125.00', 'currency' => 'PLN']],
                    ]],
                ]],
            ], 200),
        ]);

        $this->createOvokoAccount();

        $response = $this->getJson('/tools/import-marketplace-orders?token=gps_images_import_2026&marketplace=ovoko&dry_run=1&date_from=2026-06-01&date_to=2026-06-26&limit=50&include_debug=1');

        $response->assertOk()
            ->assertJsonPath('marketplaces.ovoko.api_http_status', 200)
            ->assertJsonPath('marketplaces.ovoko.ovoko_status_code', 'R200')
            ->assertJsonPath('marketplaces.ovoko.orders_fetched', 2)
            ->assertJsonPath('marketplaces.ovoko.would_import.0.total_amount', 954.72)
            ->assertJsonPath('marketplaces.ovoko.would_import.0.delivery_amount', 454.71)
            ->assertJsonPath('marketplaces.ovoko.would_import.0.currency', 'PLN')
            ->assertJsonPath('marketplaces.ovoko.would_import.0.amount_source', 'seller')
            ->assertJsonPath('marketplaces.ovoko.would_import.0.delivery_amount_source', 'seller')
            ->assertJsonPath('marketplaces.ovoko.would_import.1.total_amount', 125.0)
            ->assertJsonPath('marketplaces.ovoko.would_import.1.delivery_amount', 0.0)
            ->assertJsonPath('marketplaces.ovoko.would_import.1.currency', 'PLN')
            ->assertJsonPath('marketplaces.ovoko.would_import.1.amount_source', 'seller')
            ->assertJsonPath('marketplaces.ovoko.safety_flags.read_only', true)
            ->assertJsonPath('marketplaces.ovoko.safety_flags.orders_changed', false)
            ->assertJsonPath('marketplaces.ovoko.safety_flags.ovoko_write', false);

        $this->assertDatabaseCount('orders', 0);
    }


    public function test_ovoko_reimport_updates_marketplace_fields_without_overwriting_manual_local_status(): void
    {
        $this->createOvokoAccount();

        Http::fake([
            'ovoko.example.test/v2/get/orders/2026-07-01/2026-07-01' => Http::response([
                'status_code' => 'R200',
                'msg' => 'OK',
                'list' => [$this->ovokoOrderPayload('new', 'Buyer Initial', '125.00')],
            ], 200),
        ]);

        $first = app(MarketplaceOrdersImportService::class)->run([
            'marketplace' => 'ovoko',
            'dry_run' => false,
            'live_import' => true,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
        ]);

        $this->assertSame(1, $first['orders_created']);
        $this->assertSame(0, $first['orders_updated']);

        $order = Order::query()->where('marketplace', 'ovoko')->where('marketplace_order_id', 'OVO-REG-1')->firstOrFail();
        $this->assertSame('new', $order->status);
        $this->assertSame('new', $order->marketplace_status);

        $order->forceFill(['status' => 'shipped'])->save();

        Http::fake([
            'ovoko.example.test/v2/get/orders/2026-07-01/2026-07-01' => Http::response([
                'status_code' => 'R200',
                'msg' => 'OK',
                'list' => [$this->ovokoOrderPayload('new', 'Buyer Updated', '175.00')],
            ], 200),
        ]);

        $second = app(MarketplaceOrdersImportService::class)->run([
            'marketplace' => 'ovoko',
            'dry_run' => false,
            'live_import' => true,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
        ]);

        $this->assertSame(0, $second['orders_created']);
        $this->assertSame(1, $second['orders_updated']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);

        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertSame('new', $order->marketplace_status);
        $this->assertSame('Buyer Updated', $order->customer_name);
        $this->assertSame('175.00', $order->total);
    }

    public function test_ebay_de_and_fr_order_import_is_normalized_to_single_ebay_feed(): void
    {
        Http::fake([
            'allegro.example.test/order/checkout-forms*' => Http::response(['checkoutForms' => []], 200),
            'ebay.example.test/sell/fulfillment/v1/order*' => Http::response([
                'orders' => [
                    ['orderId' => '01-14850-71691', 'orderFulfillmentStatus' => 'FULFILLED', 'creationDate' => '2026-06-29T08:00:00.000Z', 'marketplaceId' => 'EBAY_DE', 'pricingSummary' => ['total' => ['value' => '10.00', 'currency' => 'EUR']]],
                    ['orderId' => '16-14822-57334', 'orderFulfillmentStatus' => 'NOT_STARTED', 'creationDate' => '2026-06-29T09:00:00.000Z', 'marketplaceId' => 'EBAY_FR', 'pricingSummary' => ['total' => ['value' => '20.00', 'currency' => 'EUR']]],
                    ['orderId' => '12-14828-09830', 'orderFulfillmentStatus' => 'NOT_STARTED', 'creationDate' => '2026-06-29T10:00:00.000Z', 'pricingSummary' => ['total' => ['value' => '30.00', 'currency' => 'EUR']]],
                ],
            ], 200),
        ]);

        $this->createAllegroAccount();
        $this->createEbayAccount('ebay_de', 'EBAY_DE');
        $this->createEbayAccount('ebay_fr', 'EBAY_FR');

        $response = $this->getJson('/tools/import-marketplace-orders?token=gps_images_import_2026&marketplace=allegro,ebay_de,ebay_fr&dry_run=1&date_from=2026-06-29&limit=20&include_debug=1');

        $response->assertOk()
            ->assertJsonPath('requested_channels', ['allegro', 'ebay_de', 'ebay_fr'])
            ->assertJsonPath('normalized_channels', ['allegro', 'ebay'])
            ->assertJsonMissingPath('marketplaces.ebay_de')
            ->assertJsonMissingPath('marketplaces.ebay_fr')
            ->assertJsonPath('marketplaces.ebay.source_account_code', 'ebay_de')
            ->assertJsonPath('marketplaces.ebay.requested_marketplace_id', 'EBAY_DE')
            ->assertJsonPath('marketplaces.ebay.orders_fetched', 3)
            ->assertJsonPath('marketplaces.ebay.warnings.0.code', 'ebay_shared_order_feed')
            ->assertJsonPath('marketplaces.ebay.would_import.0.marketplace', 'ebay')
            ->assertJsonPath('marketplaces.ebay.would_import.0.dedupe_key', 'ebay|01-14850-71691')
            ->assertJsonPath('orders_fetched', 3);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_ovoko_live_import_maps_item_by_marketplace_item_id_and_dispatches_sold_flow(): void
    {
        $this->createOvokoAccount();
        \Illuminate\Support\Facades\DB::table('parts')->insert([
            'id' => 7820,
            'sku' => 'GPS-GMAIL-60958',
            'name' => 'Czujnik kąta skrętu',
            'quantity' => 1,
            'status' => 'ready',
            'is_visible_storefront' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Models\MarketplaceListing::query()->create([
            'id' => 6006,
            'marketplace' => 'ovoko',
            'part_id' => 7820,
            'external_offer_id' => '11672',
            'sku' => 'GPS-GMAIL-60958',
            'status' => 'imported',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
        ]);

        $this->mock(\App\Services\Marketplace\PartAvailabilityEventService::class, function ($mock): void {
            $mock->shouldReceive('sold')->once()->with(\Mockery::on(fn (array $event): bool =>
                $event['source_channel'] === 'ovoko'
                && $event['part_id'] === 7820
                && $event['source_order_id'] === '8755665'
                && $event['source_marketplace_item_id'] === '11672'
                && $event['external_listing_id'] === '11672'
            ))->andReturn(['ok' => true, 'status' => 'processed', 'part_id' => 7820]);
        });

        \Illuminate\Support\Facades\Http::fake([
            'ovoko.example.test/v2/get/orders/2026-07-07/2026-07-07' => \Illuminate\Support\Facades\Http::response([
                'status_code' => 'R200',
                'msg' => 'OK',
                'list' => [[
                    'order_id' => '8755665',
                    'order_status' => 'NEW',
                    'order_date' => '2026-07-07 13:37:42',
                    'client_name' => 'Buyer',
                    'total_price' => ['seller' => ['amount' => '100.00', 'currency' => 'PLN']],
                    'item_list' => [[
                        'item_id' => '11672',
                        'title' => 'Czujnik kąta skrętu',
                        'quantity' => 1,
                        'sell_price' => ['seller' => ['amount' => '100.00', 'currency' => 'PLN']],
                    ]],
                ]],
            ], 200),
        ]);

        $result = app(\App\Services\Marketplace\MarketplaceOrdersImportService::class)->run([
            'marketplace' => 'ovoko',
            'dry_run' => false,
            'live_import' => true,
            'date_from' => '2026-07-07',
            'date_to' => '2026-07-07',
        ]);

        $this->assertSame(1, $result['orders_created']);
        $this->assertDatabaseHas('order_items', [
            'marketplace' => 'ovoko',
            'marketplace_order_id' => '8755665',
            'marketplace_item_id' => '11672',
            'part_id' => 7820,
            'sku' => '',
            'offer_id' => '',
        ]);
    }


    private function ovokoOrderPayload(string $status, string $buyerName, string $total): array
    {
        return [
            'order_id' => 'OVO-REG-1',
            'order_status' => $status,
            'order_date' => '2026-07-01 10:00:00',
            'client_name' => $buyerName,
            'client_email' => 'buyer@example.test',
            'client_phone' => '123456789',
            'client_address' => 'Main 1',
            'client_postcode' => '00-001',
            'client_city' => 'Warsaw',
            'client_country' => 'PL',
            'total_price' => ['seller' => ['amount' => $total, 'currency' => 'PLN']],
            'shipping_price' => ['seller' => ['amount' => '25.00', 'currency' => 'PLN']],
            'item_list' => [[
                'item_id' => 'OVO-ITEM-1',
                'title' => 'Ovoko part',
                'quantity' => 1,
                'sell_price' => ['seller' => ['amount' => '150.00', 'currency' => 'PLN']],
            ]],
        ];
    }

    private function createOvokoAccount(): void
    {
        MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://ovoko.example.test',
            'api_mode' => 'dry_run',
            'api_credentials' => ['username' => 'user', 'password' => 'pass', 'user_token' => 'token'],
        ]);
    }

    private function createEbayAccount(string $code, string $marketplaceId): void
    {
        MarketplaceAccount::query()->create([
            'marketplace' => $code,
            'code' => $code,
            'name' => $code,
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://ebay.example.test',
            'api_mode' => 'dry_run',
            'api_credentials' => ['access_token' => 'secret-token', 'scopes' => 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'],
            'api_settings' => ['marketplace_id' => $marketplaceId],
        ]);
    }

    private function createAllegroAccount(): void
    {
        MarketplaceAccount::query()->create([
            'marketplace' => 'allegro',
            'code' => 'allegro_main',
            'name' => 'Allegro main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://allegro.example.test',
            'api_mode' => 'dry_run',
            'api_credentials' => ['access_token' => 'secret-token'],
        ]);
    }
}

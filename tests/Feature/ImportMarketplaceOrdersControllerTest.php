<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
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
            ->assertJsonPath('marketplaces.allegro.would_import.0.ordered_at', '2026-06-10T12:00:00.000Z')
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

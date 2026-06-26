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

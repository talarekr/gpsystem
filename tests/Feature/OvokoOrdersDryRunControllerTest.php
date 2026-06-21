<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OvokoOrdersDryRunControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_classifies_conflicts_already_zero_actionable_and_unmatched_items_without_writes(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/orders/2026-06-01/2026-06-21' => Http::response([
                'status_code' => 'R200',
                'msg' => 'OK',
                'list' => [[
                    'order_id' => 'OV-1',
                    'item_list' => [
                        ['item' => ['id' => '5515'], 'name' => 'Conflict item'],
                        ['item' => ['id' => '7139'], 'name' => 'Already zero item'],
                        ['item' => ['id' => '9999'], 'name' => 'Actionable item'],
                        ['item' => ['id' => '4040'], 'name' => 'Unmatched item'],
                    ],
                ]],
            ], 200),
        ]);

        MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.rrr.lt',
            'api_mode' => 'dry_run',
            'api_credentials' => [
                'username' => 'secret-user',
                'password' => 'secret-password',
                'user_token' => 'secret-token',
            ],
        ]);

        DB::table('parts')->insert([
            ['id' => 7139, 'sku' => 'ZERO', 'name' => 'Already zero part', 'quantity' => 0, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9999, 'sku' => 'LIVE', 'name' => 'Actionable part', 'quantity' => 2, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('marketplace_listings')->insert([
            ['marketplace' => 'ovoko', 'part_id' => null, 'external_offer_id' => '5515', 'title' => 'Conflict: duplicate Ovoko ID 5515', 'sync_status' => 'conflict', 'match_status' => 'conflict', 'currency' => 'PLN', 'created_at' => now(), 'updated_at' => now()],
            ['marketplace' => 'ovoko', 'part_id' => 7139, 'external_offer_id' => '7139', 'title' => 'Already zero listing', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'currency' => 'PLN', 'created_at' => now(), 'updated_at' => now()],
            ['marketplace' => 'ovoko', 'part_id' => 9999, 'external_offer_id' => '9999', 'title' => 'Actionable listing', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'currency' => 'PLN', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->getJson('/tools/ovoko-orders-dry-run?token=gps_images_import_2026&from=2026-06-01&to=2026-06-21');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('orders_count', 1)
            ->assertJsonPath('order_items_count', 4)
            ->assertJsonPath('matched_items_count', 3)
            ->assertJsonPath('unmatched_items_count', 1)
            ->assertJsonPath('conflict_items_count', 1)
            ->assertJsonPath('already_zero_items_count', 1)
            ->assertJsonPath('would_mark_sold_count', 1)
            ->assertJsonPath('would_set_quantity_zero_count', 1)
            ->assertJsonPath('samples_conflict_items.0.action', 'no_action_conflict_listing')
            ->assertJsonPath('samples_already_zero_items.0.action', 'already_quantity_zero_no_action')
            ->assertJsonPath('samples_unmatched_items.0.action', 'no_action_unmatched')
            ->assertJsonPath('samples_would_update_parts.0.action', 'would_mark_sold_and_set_quantity_0');

        $this->assertDatabaseHas('parts', ['id' => 7139, 'quantity' => 0, 'status' => 'published']);
        $this->assertDatabaseHas('parts', ['id' => 9999, 'quantity' => 2, 'status' => 'published']);
    }

    public function test_it_exports_all_unmatched_order_items_to_private_csv_without_writes_or_pii(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/orders/2026-06-01/2026-06-21' => Http::response([
                'status_code' => 'R200',
                'msg' => 'OK',
                'list' => [[
                    'order_id' => 'OV-CSV-1',
                    'order_date' => '2026-06-10',
                    'client_address_country' => 'DE',
                    'client_email' => 'buyer@example.test',
                    'client_phone' => '+48123123123',
                    'client_name_surname' => 'Private Buyer',
                    'total_price' => ['seller' => ['amount' => '123.45', 'currency' => 'EUR']],
                    'item_list' => [
                        ['id' => '1111', 'name' => 'Unmatched root id', 'price' => '50.00', 'currency' => 'EUR', 'sku' => 'MISSING-SKU'],
                        ['item' => ['id' => '2222'], 'name' => 'Unmatched nested id', 'sell_price' => ['seller' => ['amount' => '73.45', 'currency' => 'EUR']]],
                        ['id' => '3333', 'name' => 'Matched item', 'price' => '1.00', 'currency' => 'EUR'],
                    ],
                ]],
            ], 200),
        ]);

        MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.rrr.lt',
            'api_mode' => 'dry_run',
            'api_credentials' => ['username' => 'secret-user', 'password' => 'secret-password', 'user_token' => 'secret-token'],
        ]);

        DB::table('parts')->insert(['id' => 3333, 'sku' => 'MATCHED', 'name' => 'Matched part', 'quantity' => 4, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => 3333, 'external_offer_id' => '3333', 'title' => 'Matched listing', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson('/tools/export-ovoko-orders-unmatched?token=gps_images_import_2026&from=2026-06-01&to=2026-06-21');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('orders_count', 1)
            ->assertJsonPath('order_items_count', 3)
            ->assertJsonPath('unmatched_items_count', 2)
            ->assertJsonPath('rows_count', 2);

        $file = $response->json('file');
        $this->assertIsString($file);
        $this->assertFileExists($file);
        $csv = file_get_contents($file);
        $this->assertStringContainsString('ovoko_order_id,order_date,buyer_country,ovoko_part_id,item_name,unit_price,currency,order_total_seller_amount,order_total_seller_currency,match_status,reason,laravel_part_id,sku,notes,manual_action', $csv);
        $this->assertStringContainsString('OV-CSV-1,2026-06-10,DE,1111,"Unmatched root id",50.00,EUR,123.45,EUR,unmatched,"no marketplace listing for ovoko_part_id",,MISSING-SKU,,', $csv);
        $this->assertStringContainsString('OV-CSV-1,2026-06-10,DE,2222,"Unmatched nested id",73.45,EUR,123.45,EUR,unmatched,"no marketplace listing for ovoko_part_id",,,,', $csv);
        $this->assertStringNotContainsString('buyer@example.test', $csv);
        $this->assertStringNotContainsString('+48123123123', $csv);
        $this->assertStringNotContainsString('Private Buyer', $csv);
        $this->assertDatabaseHas('parts', ['id' => 3333, 'quantity' => 4, 'status' => 'published']);
    }

}

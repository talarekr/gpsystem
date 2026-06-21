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
}

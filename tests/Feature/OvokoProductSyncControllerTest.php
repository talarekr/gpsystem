<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OvokoProductSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_previews_ready_missing_ovoko_listing_without_marketplace_writes(): void
    {
        Http::fake(['https://cdn.example.test/*' => Http::response('', 200)]);

        DB::table('part_categories')->insert(['id' => 10, 'name' => 'Gearboxes', 'category_path' => 'Parts > Gearboxes', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 10, 'channel' => 'ovoko', 'external_category_id' => 'OV-CAT-10', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('storage_locations')->insert(['id' => 5, 'name' => 'A1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert(['id' => 100, 'sku' => 'GPS-100', 'name' => 'Ready gearbox', 'part_number' => 'PN-100', 'description' => 'Complete description', 'price' => 110, 'ovoko_price' => 120, 'currency' => 'PLN', 'quantity' => 2, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 10, 'storage_location_id' => 5, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('part_images')->insert(['part_id' => 100, 'path' => 'https://cdn.example.test/part-100.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson('/tools/dry-run-ovoko-product-sync?token=gps_images_import_2026&limit=50&page=1&sample_limit=20');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('would_create_ovoko_count', 1)
            ->assertJsonPath('sample_payloads.0.will_make_ovoko_request', false)
            ->assertJsonPath('sample_payloads.0.part_number', 'PN-100')
            ->assertJsonPath('sample_payloads.0.price', 120)
            ->assertJsonPath('sample_payloads.0.ovoko_category_id', 'OV-CAT-10');

        Http::assertSentCount(1);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => 100, 'marketplace' => 'ovoko']);
    }

    public function test_dry_run_blocks_missing_price_images_category_needs_review_and_existing_listing(): void
    {
        Http::fake();

        DB::table('parts')->insert([
            ['id' => 201, 'name' => 'No price', 'part_number' => 'PN-201', 'description' => 'Desc', 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 202, 'name' => 'Needs review', 'part_number' => 'PN-202', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 203, 'name' => 'Listed', 'part_number' => 'PN-203', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => 203, 'external_offer_id' => 'OV-203', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson('/tools/dry-run-ovoko-product-sync?token=gps_images_import_2026&include_needs_review=1&limit=50');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('would_create_ovoko_count', 0)
            ->assertJsonPath('already_has_ovoko_listing_count', 1)
            ->assertJsonPath('blockers.missing_price', 1)
            ->assertJsonPath('blockers.missing_images', 3)
            ->assertJsonPath('blockers.missing_ovoko_category_mapping', 3)
            ->assertJsonPath('blockers.already_has_ovoko_listing', 1);
    }

    public function test_needs_listing_parts_are_excluded_by_default(): void
    {
        DB::table('parts')->insert(['id' => 301, 'name' => 'Needs listing', 'part_number' => 'PN-301', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => true, 'needs_review' => false, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson('/tools/dry-run-ovoko-product-sync?token=gps_images_import_2026');

        $response->assertOk()
            ->assertJsonPath('local_candidate_parts_count', 0)
            ->assertJsonPath('would_create_ovoko_count', 0);
    }
}

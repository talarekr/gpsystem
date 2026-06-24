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
            ->assertJsonPath('missing_ovoko_listing_candidate_count', 2)
            ->assertJsonPath('blockers.missing_ovoko_category_mapping', 3)
            ->assertJsonPath('blockers.already_has_ovoko_listing', 1)
            ->assertJsonPath('top_blockers_already_listed.already_has_ovoko_listing', 1)
            ->assertJsonPath('top_blockers_missing_listing.missing_price', 1)
            ->assertJsonPath('sample_missing_listing_blocked.0.has_ovoko_listing', false)
            ->assertJsonMissingPath('sample_missing_listing_blocked.0.ovoko_external_id')
            ->assertJsonPath('sample_missing_listing_blocked.0.storage_location.source', 'parts.storage_location_id -> storage_locations.name')
            ->assertJsonPath('sample_missing_listing_blocked.0.ovoko_category_mapping.source', 'marketplace_category_mappings.local_category_id = parts.category_id and channel = ovoko');
    }

    public function test_dry_run_separates_blockers_for_missing_listing_candidates_from_already_listed_parts(): void
    {
        Http::fake();

        DB::table('parts')->insert([
            ['id' => 401, 'name' => 'Already listed without data', 'part_number' => 'PN-401', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 402, 'name' => 'Create candidate without data', 'part_number' => 'PN-402', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => 401, 'external_offer_id' => 'OV-401', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson('/tools/dry-run-ovoko-product-sync?token=gps_images_import_2026&include_already_listed=0&limit=50');

        $response->assertOk()
            ->assertJsonPath('local_candidate_parts_count', 2)
            ->assertJsonPath('already_has_ovoko_listing_count', 1)
            ->assertJsonPath('missing_ovoko_listing_candidate_count', 1)
            ->assertJsonPath('top_blockers_already_listed.already_has_ovoko_listing', 1)
            ->assertJsonPath('top_blockers_missing_listing.missing_storage_location', 1)
            ->assertJsonPath('top_blockers_missing_listing.missing_ovoko_category_mapping', 1)
            ->assertJsonPath('sample_already_listed_blocked.0.part_id', 401)
            ->assertJsonPath('sample_missing_listing_blocked.0.part_id', 402)
            ->assertJsonPath('sample_create_missing_blocked.0.part_id', 402)
            ->assertJsonPath('sample_missing_listing_blocked.0.has_ovoko_listing', false);
    }

    public function test_needs_listing_parts_are_excluded_by_default(): void
    {
        DB::table('parts')->insert(['id' => 301, 'name' => 'Needs listing', 'part_number' => 'PN-301', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => true, 'needs_review' => false, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson('/tools/dry-run-ovoko-product-sync?token=gps_images_import_2026');

        $response->assertOk()
            ->assertJsonPath('local_candidate_parts_count', 0)
            ->assertJsonPath('would_create_ovoko_count', 0);
    }
    public function test_category_data_sources_discovers_existing_ovoko_like_category_data_read_only(): void
    {
        DB::table('part_categories')->insert([
            ['id' => 610, 'name' => 'Gearboxes', 'slug' => 'gearboxes', 'category_path' => 'Parts > Gearboxes', 'source_system' => 'ovoko_old', 'external_id' => 'OV-OLD-610', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 611, 'name' => 'Unmatched local', 'slug' => 'unmatched-local', 'category_path' => 'Parts > Unmatched local', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_category_mappings')->insert([
            ['local_category_id' => 610, 'channel' => 'ovoko_old', 'external_category_id' => 'OV-CAT-610', 'external_category_name' => 'Gearboxes', 'external_category_path' => 'Parts > Gearboxes', 'created_at' => now(), 'updated_at' => now()],
            ['local_category_id' => 611, 'channel' => 'rrr_lt', 'external_category_id' => 'RRR-611', 'external_category_name' => 'Other', 'external_category_path' => 'Parts > Other', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('parts')->insert(['id' => 620, 'name' => 'Candidate gearbox', 'part_number' => 'PN-620', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 610, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson('/tools/check-ovoko-category-data-sources?token=gps_images_import_2026&sample_limit=10');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('local_update', false)
            ->assertJsonFragment(['table' => 'part_categories'])
            ->assertJsonFragment(['table' => 'marketplace_category_mappings'])
            ->assertJsonFragment(['channel' => 'ovoko_old', 'count' => 1])
            ->assertJsonFragment(['channel' => 'rrr_lt', 'count' => 1])
            ->assertJsonFragment(['confidence' => 'exact_path_match'])
            ->assertJsonFragment(['possible_external_category_id' => 'OV-CAT-610'])
            ->assertJsonFragment(['part_number' => 'PN-620']);

        $this->assertDatabaseCount('marketplace_category_mappings', 2);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => 620]);
    }


    public function test_inspect_ovoko_category_legacy_payloads_finds_rrr_candidates_read_only(): void
    {
        DB::table('part_categories')->insert([
            ['id' => 31, 'name' => 'Engines', 'slug' => 'engines', 'category_path' => 'Parts > Engines', 'external_id' => 'OLD-31', 'legacy_payload' => json_encode(['marketplace_mappings' => ['rrr.lt' => ['category_id' => 'RRR-31', 'category_name' => 'Engines', 'category_path' => 'RRR > Engines']]]), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 32, 'name' => 'Doors', 'slug' => 'doors', 'category_path' => 'Parts > Doors', 'external_id' => 'OLD-32', 'legacy_payload' => json_encode(['marketplaces' => ['ovoko' => ['externalCategoryId' => 'OV-32', 'category_name' => 'Doors']]]), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->getJson('/tools/inspect-ovoko-category-legacy-payloads?token=gps_images_import_2026&sample_limit=10');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('categories_checked', 2)
            ->assertJsonPath('legacy_payload_non_empty_count', 2)
            ->assertJsonFragment(['possible_external_category_id' => 'RRR-31'])
            ->assertJsonFragment(['possible_external_category_id' => 'OV-32'])
            ->assertJsonFragment(['local_category_id' => 31, 'ovoko_external_category_id' => 'RRR-31'])
            ->assertJsonPath('sample_current_missing_ovoko_mapping_with_legacy_payload.0.has_ovoko_or_rrr', true);

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

}

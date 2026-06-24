<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_dry_run_ovoko_category_mapping_from_linked_products_builds_consensus_preview_read_only(): void
    {
        MarketplaceAccount::query()->create(['id' => 90, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('part_categories')->insert(['id' => 31, 'name' => 'Engines', 'category_path' => 'Parts > Engines', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert([
            ['id' => 701, 'name' => 'Engine A', 'part_number' => 'PN-701', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 31, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 702, 'name' => 'Engine B', 'part_number' => 'PN-702', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 31, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_listings')->insert([
            ['marketplace' => 'ovoko', 'part_id' => 701, 'external_offer_id' => 'OV-701', 'created_at' => now(), 'updated_at' => now()],
            ['marketplace' => 'ovoko', 'part_id' => 702, 'external_offer_id' => 'OV-702', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Http::fake([
            'https://ovoko.example.test/get/categories' => Http::response(['status_code' => 'R200', 'list' => [
                ['id' => 'OV-ROOT', 'parent_id' => null, 'level' => 1, 'pl' => 'Części', 'en' => 'Parts'],
                ['id' => 'OV-PARENT', 'parent_id' => 'OV-ROOT', 'level' => 2, 'pl' => 'Silnik', 'en' => 'Engine'],
                ['id' => 'OV-CAT-31', 'parent_id' => 'OV-PARENT', 'level' => 3, 'pl' => 'Silniki', 'en' => 'Engines'],
            ]], 200),
            'https://ovoko.example.test/v2/get/parts*' => Http::response(['status_code' => 'R200', 'data' => [
                ['id' => 'OV-701', 'name' => 'Engine A', 'category_id' => 'OV-CAT-31'],
                ['id' => 'OV-702', 'name' => 'Engine B', 'category_id' => 'OV-CAT-31'],
            ]], 200),
        ]);

        $response = $this->getJson('/tools/dry-run-ovoko-category-mapping-from-linked-products?token=gps_images_import_2026&limit=100&page=1&sample_limit=50&only_missing_ovoko_category_mapping=1');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('linked_products_checked', 2)
            ->assertJsonPath('linked_products_with_ovoko_category', 2)
            ->assertJsonPath('suggested_mapping_count', 1)
            ->assertJsonPath('ambiguous_mapping_count', 0)
            ->assertJsonPath('suggested_mappings.0.local_category_id', 31)
            ->assertJsonPath('suggested_mappings.0.ovoko_category_id', 'OV-CAT-31')
            ->assertJsonPath('suggested_mappings.0.ovoko_category_name', 'Silniki')
            ->assertJsonPath('suggested_mappings.0.ovoko_category_path', 'Części > Silnik > Silniki')
            ->assertJsonPath('suggested_mappings.0.confidence', 'high')
            ->assertJsonPath('suggested_mappings.0.match_type', 'linked_products_consensus');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_preview_ovoko_category_from_linked_products_marks_ambiguous_read_only(): void
    {
        MarketplaceAccount::query()->create(['id' => 91, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('part_categories')->insert(['id' => 31, 'name' => 'Engines', 'category_path' => 'Parts > Engines', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert([
            ['id' => 711, 'name' => 'Engine A', 'part_number' => 'PN-711', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 31, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 712, 'name' => 'Engine B', 'part_number' => 'PN-712', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 31, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_listings')->insert([
            ['marketplace' => 'ovoko', 'part_id' => 711, 'external_offer_id' => 'OV-711', 'created_at' => now(), 'updated_at' => now()],
            ['marketplace' => 'ovoko', 'part_id' => 712, 'external_offer_id' => 'OV-712', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Http::fake([
            'https://ovoko.example.test/get/categories' => Http::response(['status_code' => 'R200', 'list' => []], 200),
            'https://ovoko.example.test/v2/get/parts*' => Http::response(['status_code' => 'R200', 'data' => [
                ['id' => 'OV-711', 'category' => ['id' => 'OV-CAT-A', 'name' => 'A', 'path' => 'Ovoko > A']],
                ['id' => 'OV-712', 'category' => ['id' => 'OV-CAT-B', 'name' => 'B', 'path' => 'Ovoko > B']],
            ]], 200),
        ]);

        $response = $this->getJson('/tools/preview-ovoko-category-from-linked-products?token=gps_images_import_2026&local_category_id=31&sample_limit=50');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('local_category_id', 31)
            ->assertJsonPath('linked_products_checked', 2)
            ->assertJsonPath('ambiguous', true)
            ->assertJsonPath('suggested_mapping', null)
            ->assertJsonCount(2, 'observed_ovoko_categories');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_fetch_ovoko_category_tree_preview_is_read_only(): void
    {
        MarketplaceAccount::query()->create(['id' => 92, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake(['https://ovoko.example.test/get/categories' => Http::response(['status_code' => 'R200', 'list' => [
            ['id' => '1', 'parent_id' => null, 'level' => 1, 'pl' => 'Części', 'en' => 'Parts'],
            ['id' => '2', 'parent_id' => '1', 'level' => 2, 'pl' => 'Nadwozie', 'en' => 'Body'],
            ['id' => '3', 'parent_id' => '2', 'level' => 3, 'pl' => 'Drzwi', 'en' => 'Door'],
        ]], 200)]);

        $response = $this->getJson('/tools/fetch-ovoko-category-tree-preview?token=gps_images_import_2026&sample_limit=10');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('category_count', 3)
            ->assertJsonPath('level_counts.3', 1)
            ->assertJsonPath('id_map.3.pl', 'Drzwi')
            ->assertJsonPath('sample_full_pl_paths.0.pl_path', 'Części > Nadwozie > Drzwi');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }



    public function test_debug_ovoko_linked_product_raw_fields_shows_category_like_fields_read_only(): void
    {
        MarketplaceAccount::query()->create(['id' => 93, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake([
            'https://ovoko.example.test/v2/get/part/10776' => Http::response(['status_code' => 'R200', 'data' => ['id' => '10776', 'name' => 'Part', 'category_id' => '123', 'category_title_path' => 'A > B', 'category' => ['id' => '123'], 'group' => ['name' => 'G']]], 200),
        ]);

        $response = $this->getJson('/tools/debug-ovoko-linked-product-raw-fields?token=gps_images_import_2026&limit=1&ovoko_part_ids=10776');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('products.0.found_in_ovoko_response', true)
            ->assertJsonPath('products.0.has_category_id', true)
            ->assertJsonPath('products.0.category_id', '123')
            ->assertJsonPath('products.0.has_category_title_path', true)
            ->assertJsonPath('products.0.has_category', true)
            ->assertJsonPath('products.0.has_group', true)
            ->assertJsonPath('products.0.normalized_category.ovoko_category_id', '123');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_debug_ovoko_part_detail_endpoints_compares_variants_and_selects_matching_id_read_only(): void
    {
        MarketplaceAccount::query()->create(['id' => 95, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake([
            'https://ovoko.example.test/v2/get/parts/10776' => Http::response(['status_code' => 'R200', 'data' => ['id' => '3', 'name' => 'Wrong default', 'category_id' => '1689']], 200),
            'https://ovoko.example.test/v2/get/part/10776' => Http::response(['status_code' => 'R200', 'data' => ['id' => '10776', 'name' => 'Requested part', 'category_id' => '123', 'shop_url' => 'https://shop.example.test/10776']], 200),
            'https://ovoko.example.test/get/part/10776' => Http::response(['status_code' => 'R404', 'msg' => 'Not found'], 200),
            'https://ovoko.example.test/v2/get/parts' => Http::response(['status_code' => 'R200', 'data' => []], 200),
        ]);

        $response = $this->getJson('/tools/debug-ovoko-part-detail-endpoints?token=gps_images_import_2026&ovoko_part_id=10776');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('ovoko_read_request', true)
            ->assertJsonPath('ovoko_part_id', '10776')
            ->assertJsonPath('tested_endpoints.0.matched_requested_id', false)
            ->assertJsonPath('tested_endpoints.0.returned_raw_id', '3')
            ->assertJsonPath('tested_endpoints.1.matched_requested_id', true)
            ->assertJsonPath('best_match.returned_raw_id', '10776')
            ->assertJsonPath('best_match.returned_category_id', '123')
            ->assertJsonFragment(['endpoint_returned_success_but_not_requested_part_id']);

        Http::assertSentCount(6);
        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_linked_products_mapping_uses_snapshot_pages_for_linked_ids(): void
    {
        MarketplaceAccount::query()->create(['id' => 94, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('part_categories')->insert(['id' => 32, 'name' => 'Gearbox', 'category_path' => 'Parts > Gearbox', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert(['id' => 721, 'name' => 'Gearbox A', 'part_number' => 'PN-721', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 32, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => 721, 'external_offer_id' => '10776', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake([
            'https://ovoko.example.test/get/categories' => Http::response(['status_code' => 'R200', 'list' => [
                ['id' => '1', 'parent_id' => null, 'level' => 1, 'pl' => 'Części', 'en' => 'Parts'],
                ['id' => '2', 'parent_id' => '1', 'level' => 2, 'pl' => 'Skrzynia', 'en' => 'Gearbox'],
                ['id' => '123', 'parent_id' => '2', 'level' => 3, 'pl' => 'Automatyczna skrzynia biegów', 'en' => 'Automatic gearbox'],
            ]], 200),
            'https://ovoko.example.test/v2/get/parts*' => Http::response(['status_code' => 'R200', 'data' => [['id' => '10776', 'name' => 'Gearbox A', 'category_id' => '123']]], 200),
        ]);

        $response = $this->getJson('/tools/dry-run-ovoko-category-mapping-from-linked-products?token=gps_images_import_2026&limit=100&page=1&sample_limit=50&only_missing_ovoko_category_mapping=1');

        $response->assertOk()
            ->assertJsonPath('linked_products_checked', 1)
            ->assertJsonPath('linked_products_with_ovoko_category', 1)
            ->assertJsonPath('suggested_mapping_count', 1)
            ->assertJsonPath('suggested_mappings.0.ovoko_category_id', '123')
            ->assertJsonPath('suggested_mappings.0.ovoko_category_path', 'Części > Skrzynia > Automatyczna skrzynia biegów');

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_linked_products_mapping_does_not_use_detail_endpoint_when_snapshot_misses_id(): void
    {
        MarketplaceAccount::query()->create(['id' => 96, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('part_categories')->insert(['id' => 33, 'name' => 'Battery', 'category_path' => 'Parts > Battery', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert(['id' => 731, 'name' => 'Battery A', 'part_number' => 'PN-731', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 33, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => 731, 'external_offer_id' => '10776', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake([
            'https://ovoko.example.test/get/categories' => Http::response(['status_code' => 'R200', 'list' => []], 200),
            'https://ovoko.example.test/v2/get/parts*' => Http::response(['status_code' => 'R200', 'data' => []], 200),
        ]);

        $response = $this->getJson('/tools/dry-run-ovoko-category-mapping-from-linked-products?token=gps_images_import_2026&limit=100&page=1&sample_limit=50&only_missing_ovoko_category_mapping=1');

        $response->assertOk()
            ->assertJsonPath('linked_products_checked', 1)
            ->assertJsonPath('linked_products_with_ovoko_category', 0)
            ->assertJsonPath('suggested_mapping_count', 0)
            ->assertJsonPath('unmapped_or_missing_category_count', 1)
            ->assertJsonPath('sample_errors.0.type', 'ovoko_part_not_found_in_snapshot')
            ->assertJsonPath('sample_errors.0.ovoko_part_id', '10776');

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_debug_ovoko_find_linked_part_in_snapshot_reports_match_read_only(): void
    {
        MarketplaceAccount::query()->create(['id' => 97, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        Http::fake([
            'https://ovoko.example.test/get/categories' => Http::response(['status_code' => 'R200', 'list' => [
                ['id' => '1', 'parent_id' => null, 'level' => 1, 'pl' => 'Części', 'en' => 'Parts'],
                ['id' => '1689', 'parent_id' => '1', 'level' => 3, 'pl' => 'Baterie', 'en' => 'Batteries'],
            ]], 200),
            'https://ovoko.example.test/v2/get/parts*' => Http::response(['status_code' => 'R200', 'data' => [
                ['id' => '10776', 'external_id' => '10776', 'name' => 'Battery', 'category_id' => '1689', 'shop_url' => 'https://shop.example.test/10776'],
            ]], 200),
        ]);

        $response = $this->getJson('/tools/debug-ovoko-find-linked-part-in-snapshot?token=gps_images_import_2026&ovoko_part_id=10776');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('ovoko_read_request', true)
            ->assertJsonPath('requested_ovoko_part_id', '10776')
            ->assertJsonPath('found', true)
            ->assertJsonPath('matched_part.page', 1)
            ->assertJsonPath('matched_part.raw_id', '10776')
            ->assertJsonPath('matched_part.external_id', '10776')
            ->assertJsonPath('matched_part.category_id', '1689')
            ->assertJsonPath('matched_part.category_path_pl', 'Części > Baterie');

        Http::assertSentCount(2);
    }


    public function test_autorun_start_and_active_resume_return_next_url_until_complete(): void
    {
        Cache::flush();
        MarketplaceAccount::query()->create(['id' => 98, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('part_categories')->insert(['id' => 131, 'name' => 'Starters', 'category_path' => 'Parts > Starters', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert(['id' => 981, 'name' => 'Starter A', 'part_number' => 'PN-981', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 131, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => 981, 'external_offer_id' => 'OV-981', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson('/tools/start-ovoko-category-mapping-autorun?token=gps_images_import_2026&batch_size=100&sample_limit=10&only_missing_ovoko_category_mapping=1');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'started')
            ->assertJsonPath('remaining_count', 1)
            ->assertJsonPath('next_url', fn ($url) => is_string($url) && str_contains($url, '/tools/run-ovoko-category-mapping-autorun') && str_contains($url, 'run_id='));

        $runId = $response->json('run_id');

        $resume = $this->getJson('/tools/start-ovoko-category-mapping-autorun?token=gps_images_import_2026&batch_size=100&sample_limit=10&only_missing_ovoko_category_mapping=1');

        $resume->assertOk()
            ->assertJsonPath('active_run', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('status', 'started')
            ->assertJsonPath('remaining_count', 1)
            ->assertJsonPath('next_url', fn ($url) => is_string($url) && str_contains($url, '/tools/run-ovoko-category-mapping-autorun') && str_contains($url, 'run_id='.$runId));
    }

    public function test_autorun_tick_processes_started_run_and_returns_next_url_when_remaining(): void
    {
        Cache::flush();
        MarketplaceAccount::query()->create(['id' => 99, 'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://ovoko.example.test', 'api_mode' => 'dry_run', 'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't']]);
        DB::table('part_categories')->insert(['id' => 132, 'name' => 'Alternators', 'category_path' => 'Parts > Alternators', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert([
            ['id' => 991, 'name' => 'Alternator A', 'part_number' => 'PN-991', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 132, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 992, 'name' => 'Alternator B', 'part_number' => 'PN-992', 'description' => 'Desc', 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'needs_listing' => false, 'needs_review' => false, 'category_id' => 132, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_listings')->insert([
            ['marketplace' => 'ovoko', 'part_id' => 991, 'external_offer_id' => 'OV-991', 'created_at' => now(), 'updated_at' => now()],
            ['marketplace' => 'ovoko', 'part_id' => 992, 'external_offer_id' => 'OV-992', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Http::fake([
            'https://ovoko.example.test/get/categories' => Http::response(['status_code' => 'R200', 'list' => []], 200),
            'https://ovoko.example.test/v2/get/parts*' => Http::response(['status_code' => 'R200', 'data' => [
                ['id' => 'OV-991', 'category' => ['id' => 'OV-CAT-132', 'name' => 'Alternators', 'path' => 'Ovoko > Alternators']],
                ['id' => 'OV-992', 'category' => ['id' => 'OV-CAT-132', 'name' => 'Alternators', 'path' => 'Ovoko > Alternators']],
            ]], 200),
        ]);

        $start = $this->getJson('/tools/start-ovoko-category-mapping-autorun?token=gps_images_import_2026&batch_size=1&sample_limit=10&only_missing_ovoko_category_mapping=1');
        $runId = $start->json('run_id');

        $tick = $this->getJson('/tools/run-ovoko-category-mapping-autorun?token=gps_images_import_2026&run_id='.$runId);

        $tick->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('processed_count', 1)
            ->assertJsonPath('remaining_count', 1)
            ->assertJsonPath('next_url', fn ($url) => is_string($url) && str_contains($url, '/tools/run-ovoko-category-mapping-autorun') && str_contains($url, 'run_id='.$runId));
    }

}

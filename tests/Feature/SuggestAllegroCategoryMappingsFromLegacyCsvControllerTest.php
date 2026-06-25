<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuggestAllegroCategoryMappingsFromLegacyCsvControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_allegro_category_mappings_from_legacy_csv_without_writes(): void
    {
        $imports = storage_path('app/imports');
        if (! is_dir($imports)) mkdir($imports, 0777, true);
        file_put_contents($imports.'/woo_allegro_legacy_mapping.csv', implode("\n", [
            'woo_product_id,sku,allegro_offer_id,raw_allegro_meta_json',
            '501,SKU-501,9001,"{""_allegro_category_id"":""123""}"',
            '502,SKU-502,9002,"{""_allegro_category_id"":""123""}"',
            '503,SKU-503,9003,"{""_allegro_category_id"":""999""}"',
            '999,SKU-999,9004,"{""_allegro_category_id"":""123""}"',
        ]));

        DB::table('part_categories')->insert(['id' => 31, 'name' => 'Silniki', 'category_path' => 'Części > Silnik > Silniki', 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_categories')->insert(['channel' => 'allegro', 'external_category_id' => '123', 'name' => 'Części samochodowe', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert([
            ['id' => 101, 'source_system' => 'woo', 'external_id' => '501', 'sku' => 'SKU-501', 'name' => 'Engine A', 'category_id' => 31, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'source_system' => 'woo', 'external_id' => '502', 'sku' => 'SKU-502', 'name' => 'Engine B', 'category_id' => 31, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 103, 'source_system' => 'woo', 'external_id' => '503', 'sku' => 'SKU-503', 'name' => 'Engine C', 'category_id' => 31, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->getJson('/tools/suggest-allegro-category-mappings-from-legacy-csv?token=gps_images_import_2026&only_public=1&only_missing_allegro=1&leaf_only=1')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('mappings_changed', false)
            ->assertJsonPath('matched_products_count', 3)
            ->assertJsonPath('unmatched_products_count', 1)
            ->assertJsonPath('suggested_mapping_count', 1)
            ->assertJsonPath('suggested_mappings.0.local_category_id', 31)
            ->assertJsonPath('suggested_mappings.0.suggested_allegro_category_id', '123')
            ->assertJsonPath('suggested_mappings.0.suggested_allegro_category_name', 'Części samochodowe')
            ->assertJsonPath('suggested_mappings.0.suggested_count', 2)
            ->assertJsonPath('suggested_mappings.0.confidence', 'low')
            ->assertJsonPath('diagnostics.allegro_category_id_from_json_count', 4)
            ->assertJsonPath('diagnostics.allegro_offer_id_from_column_count', 4);

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }
    public function test_recovers_allegro_ids_from_broken_csv_json_and_matches_by_sku(): void
    {
        $imports = storage_path('app/imports');
        if (! is_dir($imports)) mkdir($imports, 0777, true);
        file_put_contents($imports.'/woo_allegro_legacy_mapping.csv', implode("\n", [
            'woo_product_id,sku,allegro_offer_id,raw_allegro_meta_json',
            '999,SKU-FALLBACK,,{"_allegro_offer_id":"OFFER-9","_allegro_category_id":"123","other":"comma,split"}',
        ]));

        DB::table('part_categories')->insert(['id' => 31, 'name' => 'Silniki', 'category_path' => 'Części > Silnik > Silniki', 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_categories')->insert(['channel' => 'allegro', 'external_category_id' => '123', 'name' => 'Części samochodowe', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert([
            'id' => 101,
            'source_system' => 'manual',
            'external_id' => 'different',
            'sku' => 'SKU-FALLBACK',
            'name' => 'Engine A',
            'category_id' => 31,
            'price' => 1,
            'quantity' => 1,
            'status' => 'ready',
            'is_visible_storefront' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/tools/suggest-allegro-category-mappings-from-legacy-csv?token=gps_images_import_2026&only_public=1&only_missing_allegro=1&leaf_only=1')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('matched_products_count', 1)
            ->assertJsonPath('unmatched_products_count', 0)
            ->assertJsonPath('rows_with_allegro_category_id', 1)
            ->assertJsonPath('diagnostics.invalid_json_count', 0)
            ->assertJsonPath('diagnostics.allegro_category_id_from_regex_count', 1)
            ->assertJsonPath('diagnostics.allegro_offer_id_from_regex_count', 1)
            ->assertJsonPath('diagnostics.count_parts_with_sku_matching_sample', 1)
            ->assertJsonPath('diagnostics.product_match_attempts_sample.0.matched_by', 'sku')
            ->assertJsonPath('suggested_mappings.0.sample_products.0.allegro_offer_id', 'OFFER-9');

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_prefers_local_allegro_offer_listing_match_when_sku_is_empty(): void
    {
        $imports = storage_path('app/imports');
        if (! is_dir($imports)) mkdir($imports, 0777, true);
        file_put_contents($imports.'/woo_allegro_legacy_mapping.csv', implode("\n", [
            'woo_product_id,sku,allegro_offer_id,raw_allegro_meta_json',
            '999,,AL-1001,"{""_allegro_category_id"":""123""}"',
            '998,,AL-1002,"{""_allegro_category_id"":""123""}"',
        ]));

        DB::table('part_categories')->insert(['id' => 31, 'name' => 'Silniki', 'category_path' => 'Części > Silnik > Silniki', 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_categories')->insert(['channel' => 'allegro', 'external_category_id' => '123', 'name' => 'Części samochodowe', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert([
            ['id' => 101, 'source_system' => 'manual', 'external_id' => 'different-1', 'sku' => null, 'name' => 'Engine A', 'category_id' => 31, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'source_system' => 'manual', 'external_id' => 'different-2', 'sku' => null, 'name' => 'Engine B', 'category_id' => 31, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_listings')->insert([
            ['marketplace' => 'allegro', 'part_id' => 101, 'external_offer_id' => 'AL-1001', 'created_at' => now(), 'updated_at' => now()],
            ['marketplace' => 'Allegro', 'part_id' => 102, 'external_offer_id' => 'AL-1002', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->getJson('/tools/suggest-allegro-category-mappings-from-legacy-csv?token=gps_images_import_2026&only_public=1&only_missing_allegro=1&leaf_only=1')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('matched_products_count', 2)
            ->assertJsonPath('suggested_mapping_count', 1)
            ->assertJsonPath('diagnostics.sample_allegro_offer_ids.0', 'AL-1001')
            ->assertJsonPath('diagnostics.local_offer_match_strategy_used', 'marketplace_listings.external_offer_id')
            ->assertJsonPath('diagnostics.count_offer_table_matches_sample', 2)
            ->assertJsonPath('diagnostics.offer_table_raw_match_count', 2)
            ->assertJsonPath('diagnostics.offer_table_part_found_count', 2)
            ->assertJsonPath('diagnostics.offer_table_accepted_count', 2)
            ->assertJsonPath('diagnostics.sample_offer_table_matches.0.offer_id_column', 'external_offer_id')
            ->assertJsonPath('diagnostics.sample_offer_table_matches.0.accepted', true)
            ->assertJsonPath('diagnostics.product_match_attempts_sample.0.matched_by', 'marketplace_listings.external_offer_id')
            ->assertJsonPath('diagnostics.product_match_attempts_sample.0.matched_part_id', 101)
            ->assertJsonPath('diagnostics.product_match_attempts_sample.0.matched_category_id', 31);

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }


    public function test_apply_allegro_category_mappings_dry_run_does_not_write(): void
    {
        $this->seedApplyFixture(31, 'Silniki');

        $this->getJson('/tools/apply-allegro-category-mappings-from-legacy-csv?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('mappings_changed', false)
            ->assertJsonPath('would_create_count', 1)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('items.0.action', 'would_create')
            ->assertJsonPath('items.0.local_category_id', 31)
            ->assertJsonPath('items.0.suggested_allegro_category_id', '123');

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_apply_allegro_category_mappings_excludes_uncategorized_category(): void
    {
        $this->seedApplyFixture(20, 'Bez kategorii');

        $this->getJson('/tools/apply-allegro-category-mappings-from-legacy-csv?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('would_create_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('items.0.local_category_id', 20)
            ->assertJsonPath('items.0.action', 'skipped_uncategorized');

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_apply_allegro_category_mappings_confirm_creates_local_mapping_only(): void
    {
        $this->seedApplyFixture(31, 'Silniki');

        $this->getJson('/tools/apply-allegro-category-mappings-from-legacy-csv?token=gps_images_import_2026&confirm=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('read_only', false)
            ->assertJsonPath('local_update', true)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('mappings_changed', true)
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('items.0.action', 'created');

        $this->assertDatabaseHas('marketplace_category_mappings', [
            'local_category_id' => 31,
            'channel' => 'allegro',
            'external_category_id' => '123',
            'external_category_name' => 'Części samochodowe',
            'source' => 'legacy_csv_offer_id_match',
        ]);
        $this->assertDatabaseCount('marketplace_category_mappings', 1);
    }

    public function test_apply_allegro_category_mappings_skips_existing_mapping_without_duplicate(): void
    {
        $this->seedApplyFixture(31, 'Silniki');
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 31, 'channel' => 'allegro', 'external_category_id' => 'OLD', 'external_category_name' => 'Existing', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/tools/apply-allegro-category-mappings-from-legacy-csv?token=gps_images_import_2026&confirm=1')
            ->assertOk()
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('mappings_changed', false)
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('items.0.action', 'skipped_existing_mapping');

        $this->assertDatabaseCount('marketplace_category_mappings', 1);
        $this->assertDatabaseHas('marketplace_category_mappings', ['local_category_id' => 31, 'channel' => 'allegro', 'external_category_id' => 'OLD']);
    }


    public function test_allegro_legacy_category_mapping_runner_page_returns_200(): void
    {
        $this->get('/tools/allegro-legacy-category-mapping-runner?token=gps_images_import_2026')
            ->assertOk()
            ->assertSee('Allegro legacy category mapping runner')
            ->assertSee('/tools/run-allegro-legacy-category-mapping-batch');
    }

    public function test_allegro_legacy_category_mapping_batch_dry_run_returns_next_offset_and_no_writes(): void
    {
        $this->seedApplyFixture(31, 'Silniki');

        $this->getJson('/tools/run-allegro-legacy-category-mapping-batch?token=gps_images_import_2026&offset=0&batch_size=1&record_limit=2')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('processed_rows', 1)
            ->assertJsonPath('next_offset', 1)
            ->assertJsonPath('done', false)
            ->assertJsonPath('would_create_count', 1);

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_allegro_legacy_category_mapping_batch_excludes_uncategorized(): void
    {
        $this->seedApplyFixture(20, 'Bez kategorii');

        $this->getJson('/tools/run-allegro-legacy-category-mapping-batch?token=gps_images_import_2026&offset=0&batch_size=100&record_limit=5000')
            ->assertOk()
            ->assertJsonPath('would_create_count', 0)
            ->assertJsonPath('skipped_uncategorized_count', 1)
            ->assertJsonPath('items.0.action', 'skipped_uncategorized');

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_allegro_legacy_category_mapping_batch_confirm_creates_local_allegro_mapping_only(): void
    {
        $this->seedApplyFixture(31, 'Silniki');

        $this->getJson('/tools/run-allegro-legacy-category-mapping-batch?token=gps_images_import_2026&offset=0&batch_size=100&record_limit=5000&confirm=1')
            ->assertOk()
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('read_only', false)
            ->assertJsonPath('local_update', true)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('mappings_changed', true)
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('items.0.action', 'created');

        $this->assertDatabaseHas('marketplace_category_mappings', ['local_category_id' => 31, 'channel' => 'allegro', 'external_category_id' => '123']);
        $this->assertDatabaseCount('marketplace_category_mappings', 1);
    }

    public function test_allegro_legacy_category_mapping_batch_confirm_does_not_duplicate_existing_mapping(): void
    {
        $this->seedApplyFixture(31, 'Silniki');
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 31, 'channel' => 'allegro', 'external_category_id' => 'OLD', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/tools/run-allegro-legacy-category-mapping-batch?token=gps_images_import_2026&offset=0&batch_size=100&record_limit=5000&confirm=1')
            ->assertOk()
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('mappings_changed', false)
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('skipped_existing_mapping_count', 1)
            ->assertJsonPath('items.0.action', 'skipped_existing_mapping');

        $this->assertDatabaseCount('marketplace_category_mappings', 1);
        $this->assertDatabaseHas('marketplace_category_mappings', ['local_category_id' => 31, 'channel' => 'allegro', 'external_category_id' => 'OLD']);
    }

    public function test_allegro_legacy_category_mapping_batch_never_calls_external_apis(): void
    {
        Http::fake();
        $this->seedApplyFixture(31, 'Silniki');

        $this->getJson('/tools/run-allegro-legacy-category-mapping-batch?token=gps_images_import_2026&offset=0&batch_size=100&record_limit=5000&confirm=1')
            ->assertOk()
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false);

        Http::assertNothingSent();
    }

    private function seedApplyFixture(int $categoryId, string $categoryName): void
    {
        $imports = storage_path('app/imports');
        if (! is_dir($imports)) mkdir($imports, 0777, true);
        file_put_contents($imports.'/woo_allegro_legacy_mapping.csv', implode("\n", [
            'woo_product_id,sku,allegro_offer_id,raw_allegro_meta_json',
            '501,SKU-501,AL-501,"{""_allegro_category_id"":""123""}"',
            '502,SKU-502,AL-502,"{""_allegro_category_id"":""123""}"',
        ]));

        DB::table('part_categories')->insert(['id' => $categoryId, 'name' => $categoryName, 'category_path' => 'Części > '.$categoryName, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_categories')->insert(['channel' => 'allegro', 'external_category_id' => '123', 'name' => 'Części samochodowe', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parts')->insert([
            ['id' => 101, 'source_system' => 'manual', 'external_id' => 'different-1', 'sku' => null, 'name' => 'Part A', 'category_id' => $categoryId, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'source_system' => 'manual', 'external_id' => 'different-2', 'sku' => null, 'name' => 'Part B', 'category_id' => $categoryId, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('marketplace_listings')->insert([
            ['marketplace' => 'allegro', 'part_id' => 101, 'external_offer_id' => 'AL-501', 'created_at' => now(), 'updated_at' => now()],
            ['marketplace' => 'allegro', 'part_id' => 102, 'external_offer_id' => 'AL-502', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuggestAllegroCategoryMappingsFromLegacyCsvControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_allegro_category_mappings_from_legacy_csv_without_writes(): void
    {
        $imports = storage_path('app/imports');
        if (! is_dir($imports)) mkdir($imports, 0777, true);
        file_put_contents($imports.'/woo_allegro_legacy_mapping.csv', implode("\n", [
            'woo_product_id,allegro_offer_id,raw_allegro_meta_json',
            '501,9001,"{""_allegro_category_id"":""123""}"',
            '502,9002,"{""_allegro_category_id"":""123""}"',
            '503,9003,"{""_allegro_category_id"":""999""}"',
            '999,9004,"{""_allegro_category_id"":""123""}"',
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
            ->assertJsonPath('suggested_mappings.0.confidence', 'low');

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }
}

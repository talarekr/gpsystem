<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuggestOvokoCategoryMappingsFromLocalTreeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_read_only_safety_flags(): void
    {
        $this->seedCategory(10, 'Drzwi przednie');
        $this->seedPart(100, 10);
        $this->seedOvoko('OV-10', 'Przednie drzwi');

        $this->getJson('/tools/suggest-ovoko-category-mappings-from-local-tree?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('mappings_changed', false);
    }

    public function test_exact_name_match_returns_high_confidence_suggestion(): void
    {
        $this->seedCategory(11, 'Zderzak przedni', 'Części > Nadwozie > Zderzak przedni');
        $this->seedPart(101, 11);
        $this->seedOvoko('OV-11', 'Zderzak przedni', 'Części > Nadwozie > Zderzak przedni');

        $this->getJson('/tools/suggest-ovoko-category-mappings-from-local-tree?token=gps_images_import_2026&min_score=0.85')
            ->assertOk()
            ->assertJsonPath('items.0.local_category_id', 11)
            ->assertJsonPath('items.0.suggested_ovoko_category_id', 'OV-11')
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('items.0.status', 'suggested')
            ->assertJsonPath('summary.suggested_count', 1)
            ->assertJsonPath('summary.high_confidence_count', 1);
    }

    public function test_ambiguous_candidates_are_not_auto_high(): void
    {
        $this->seedCategory(12, 'Lampa tylna');
        $this->seedPart(102, 12);
        $this->seedOvoko('OV-12A', 'Lampa tylna', 'Auta > Lampa tylna');
        $this->seedOvoko('OV-12B', 'Tylna lampa', 'Auta > Tylna lampa');

        $this->getJson('/tools/suggest-ovoko-category-mappings-from-local-tree?token=gps_images_import_2026&min_score=0.85')
            ->assertOk()
            ->assertJsonPath('items.0.status', 'ambiguous')
            ->assertJsonPath('items.0.confidence', 'medium')
            ->assertJsonCount(2, 'items.0.candidates');
    }

    public function test_no_writes_to_mappings_table(): void
    {
        $this->seedCategory(13, 'Maska');
        $this->seedPart(103, 13);
        $this->seedOvoko('OV-13', 'Pokrywa silnika');

        $this->getJson('/tools/suggest-ovoko-category-mappings-from-local-tree?token=gps_images_import_2026')->assertOk();

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_filters_only_missing_ovoko_and_leaf_only_work(): void
    {
        $this->seedCategory(20, 'Nadwozie');
        $this->seedCategory(21, 'Klapa tylna', 'Nadwozie > Klapa tylna', 20);
        $this->seedCategory(22, 'Maglownica');
        $this->seedPart(121, 21);
        $this->seedPart(122, 22);
        $this->seedOvoko('OV-21', 'Pokrywa bagażnika');
        $this->seedOvoko('OV-22', 'Przekładnia kierownicza');
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 22, 'channel' => 'ovoko', 'external_category_id' => 'EXISTING', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/tools/suggest-ovoko-category-mappings-from-local-tree?token=gps_images_import_2026&only_missing_ovoko=1&leaf_only=1&include_existing=0')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.local_category_id', 21);
    }

    private function seedCategory(int $id, string $name, ?string $path = null, ?int $parentId = null): void
    {
        DB::table('part_categories')->insert(['id' => $id, 'parent_id' => $parentId, 'name' => $name, 'category_path' => $path ?? $name, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedPart(int $id, int $categoryId): void
    {
        DB::table('parts')->insert(['id' => $id, 'sku' => 'SKU-'.$id, 'name' => 'Part '.$id, 'category_id' => $categoryId, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedOvoko(string $id, string $name, ?string $path = null): void
    {
        DB::table('marketplace_categories')->insert(['channel' => 'ovoko', 'external_category_id' => $id, 'name' => $name, 'full_path' => $path ?? $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}

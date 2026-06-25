<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SetOvokoCategoryMappingsBatchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_batch_writes_nothing(): void
    {
        $this->seedCategory(402, 'Maska', 'Części > Maska');
        $this->seedOvoko('1400', 'Maska Ovoko', 'Ovoko > Maska');

        $this->getJson('/tools/set-ovoko-category-mappings-batch?token=gps_images_import_2026&confirm=0&mappings=402:1400')
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('mappings_changed', false)
            ->assertJsonPath('items.0.action', 'would_create');

        $this->assertDatabaseCount('marketplace_category_mappings', 0);
    }

    public function test_confirm_creates_batch_mappings(): void
    {
        $this->seedCategory(402, 'Maska', 'Części > Maska');
        $this->seedCategory(181, 'Drzwi', 'Części > Drzwi');
        $this->seedOvoko('1400', 'Maska Ovoko', 'Ovoko > Maska');
        $this->seedOvoko('1112', 'Drzwi Ovoko', 'Ovoko > Drzwi');

        $this->getJson('/tools/set-ovoko-category-mappings-batch?token=gps_images_import_2026&confirm=1&mappings=402:1400,181:1112')
            ->assertOk()
            ->assertJsonPath('read_only', false)
            ->assertJsonPath('local_update', true)
            ->assertJsonPath('mappings_changed', true)
            ->assertJsonPath('created_count', 2)
            ->assertJsonPath('items.0.action', 'created')
            ->assertJsonPath('items.1.action', 'created');

        $this->assertDatabaseHas('marketplace_category_mappings', ['local_category_id' => 402, 'channel' => 'ovoko', 'external_category_id' => '1400', 'source' => 'manual_review_batch', 'confidence' => 'high']);
        $this->assertDatabaseHas('marketplace_category_mappings', ['local_category_id' => 181, 'channel' => 'ovoko', 'external_category_id' => '1112']);
        $this->assertDatabaseCount('marketplace_category_mappings', 2);
    }

    public function test_existing_mappings_skipped_without_replace(): void
    {
        $this->seedCategory(402, 'Maska');
        $this->seedOvoko('1400', 'Nowa Maska');
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 402, 'channel' => 'ovoko', 'external_category_id' => 'OLD', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/tools/set-ovoko-category-mappings-batch?token=gps_images_import_2026&confirm=1&mappings=402:1400')
            ->assertOk()
            ->assertJsonPath('skipped_existing_count', 1)
            ->assertJsonPath('items.0.action', 'skipped_existing');

        $this->assertDatabaseHas('marketplace_category_mappings', ['local_category_id' => 402, 'channel' => 'ovoko', 'external_category_id' => 'OLD']);
        $this->assertDatabaseCount('marketplace_category_mappings', 1);
    }

    public function test_replace_updates_existing_mappings(): void
    {
        $this->seedCategory(402, 'Maska');
        $this->seedOvoko('1400', 'Nowa Maska', 'Ovoko > Nowa Maska');
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 402, 'channel' => 'ovoko', 'external_category_id' => 'OLD', 'external_category_name' => 'Old', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/tools/set-ovoko-category-mappings-batch?token=gps_images_import_2026&confirm=1&replace=1&mappings=402:1400')
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('items.0.action', 'updated');

        $this->assertDatabaseHas('marketplace_category_mappings', ['local_category_id' => 402, 'channel' => 'ovoko', 'external_category_id' => '1400', 'external_category_name' => 'Nowa Maska']);
        $this->assertDatabaseCount('marketplace_category_mappings', 1);
    }

    public function test_invalid_local_category_id_item_error_rest_continues(): void
    {
        $this->seedCategory(181, 'Drzwi');
        $this->seedOvoko('1112', 'Drzwi Ovoko');
        $this->seedOvoko('1400', 'Maska Ovoko');

        $this->getJson('/tools/set-ovoko-category-mappings-batch?token=gps_images_import_2026&confirm=1&mappings=999:1400,181:1112')
            ->assertOk()
            ->assertJsonPath('errors_count', 1)
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('items.0.action', 'error')
            ->assertJsonPath('items.0.error', 'local_category_id_not_found')
            ->assertJsonPath('items.1.action', 'created');

        $this->assertDatabaseCount('marketplace_category_mappings', 1);
    }

    public function test_invalid_ovoko_category_id_item_error_rest_continues(): void
    {
        $this->seedCategory(402, 'Maska');
        $this->seedCategory(181, 'Drzwi');
        $this->seedOvoko('1112', 'Drzwi Ovoko');

        $this->getJson('/tools/set-ovoko-category-mappings-batch?token=gps_images_import_2026&confirm=1&mappings=402:missing,181:1112')
            ->assertOk()
            ->assertJsonPath('errors_count', 1)
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('items.0.action', 'error')
            ->assertJsonPath('items.0.error', 'ovoko_category_id_not_found')
            ->assertJsonPath('items.1.action', 'created');

        $this->assertDatabaseCount('marketplace_category_mappings', 1);
    }

    public function test_duplicate_local_category_id_returns_item_error_and_creates_no_duplicate(): void
    {
        $this->seedCategory(402, 'Maska');
        $this->seedOvoko('1400', 'Maska One');
        $this->seedOvoko('1401', 'Maska Two');

        $this->getJson('/tools/set-ovoko-category-mappings-batch?token=gps_images_import_2026&confirm=1&mappings=402:1400,402:1401')
            ->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('errors_count', 1)
            ->assertJsonPath('items.0.action', 'created')
            ->assertJsonPath('items.1.action', 'error')
            ->assertJsonPath('items.1.error', 'duplicate_local_category_id');

        $this->assertDatabaseCount('marketplace_category_mappings', 1);
        $this->assertDatabaseHas('marketplace_category_mappings', ['local_category_id' => 402, 'channel' => 'ovoko', 'external_category_id' => '1400']);
    }

    public function test_no_products_offers_or_api_writes(): void
    {
        $this->seedCategory(402, 'Maska');
        $this->seedOvoko('1400', 'Maska Ovoko');
        $this->seedPart(10, 402);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => 10, 'external_offer_id' => 'offer-10', 'title' => 'Offer', 'currency' => 'PLN', 'created_at' => now(), 'updated_at' => now()]);
        $partBefore = (array) DB::table('parts')->where('id', 10)->first();
        $listingBefore = (array) DB::table('marketplace_listings')->where('external_offer_id', 'offer-10')->first();

        $this->getJson('/tools/set-ovoko-category-mappings-batch?token=gps_images_import_2026&confirm=1&mappings=402:1400')
            ->assertOk()
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false);

        $this->assertEquals($partBefore, (array) DB::table('parts')->where('id', 10)->first());
        $this->assertEquals($listingBefore, (array) DB::table('marketplace_listings')->where('external_offer_id', 'offer-10')->first());
    }

    private function seedCategory(int $id, string $name, ?string $path = null): void
    {
        DB::table('part_categories')->insert(['id' => $id, 'name' => $name, 'category_path' => $path ?? $name, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedOvoko(string $id, string $name, ?string $path = null): void
    {
        DB::table('marketplace_categories')->insert(['channel' => 'ovoko', 'external_category_id' => $id, 'name' => $name, 'full_path' => $path ?? $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedPart(int $id, int $categoryId): void
    {
        DB::table('parts')->insert(['id' => $id, 'sku' => 'SKU-'.$id, 'name' => 'Part '.$id, 'category_id' => $categoryId, 'price' => 1, 'quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}

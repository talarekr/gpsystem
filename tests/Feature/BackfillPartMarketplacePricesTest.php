<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillPartMarketplacePricesTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/tools/backfill-part-marketplace-prices?token=gps_images_import_2026&scope=to_publish&only_missing=1';

    public function test_dry_run_reports_store_price_for_allegro_and_store_price_plus_25_percent_for_ebay(): void
    {
        $part = $this->partWithRawPrices(['price' => 100, 'allegro_price' => null, 'ebay_price' => null]);

        $this->getJson($this->url.'&dry_run=1&confirm=0')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('would_update_parts_count', 1)
            ->assertJsonPath('updated_parts_count', 0)
            ->assertJsonPath('items.0.part_id', $part->id)
            ->assertJsonPath('items.0.new_allegro_price', 100)
            ->assertJsonPath('items.0.new_ebay_price', 125)
            ->assertJsonPath('items.0.action', 'would_update');

        $part->refresh();
        $this->assertNull($part->allegro_price);
        $this->assertNull($part->ebay_price);
    }

    public function test_confirm_sets_allegro_and_ebay_without_touching_price_or_ovoko(): void
    {
        $part = $this->partWithRawPrices(['price' => 100, 'allegro_price' => null, 'ebay_price' => null, 'ovoko_price' => 88.88]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('read_only', false)
            ->assertJsonPath('local_update', true)
            ->assertJsonPath('parts_changed', true)
            ->assertJsonPath('updated_parts_count', 1)
            ->assertJsonPath('allegro_updated_count', 1)
            ->assertJsonPath('ebay_updated_count', 1);

        $part->refresh();
        $this->assertSame('100.00', (string) $part->price);
        $this->assertSame('100.00', (string) $part->allegro_price);
        $this->assertSame('125.00', (string) $part->ebay_price);
        $this->assertSame('88.88', (string) $part->ovoko_price);
    }

    public function test_only_missing_does_not_overwrite_existing_positive_marketplace_prices(): void
    {
        $part = $this->partWithRawPrices(['price' => 100, 'allegro_price' => 90, 'ebay_price' => 140]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('updated_parts_count', 0)
            ->assertJsonPath('skipped_already_set_count', 1)
            ->assertJsonPath('items.0.action', 'skipped_already_set');

        $part->refresh();
        $this->assertSame('90.00', (string) $part->allegro_price);
        $this->assertSame('140.00', (string) $part->ebay_price);
    }

    public function test_only_missing_zero_still_does_not_overwrite_without_replace(): void
    {
        $part = $this->partWithRawPrices(['price' => 100, 'allegro_price' => 90, 'ebay_price' => 140]);

        $this->getJson('/tools/backfill-part-marketplace-prices?token=gps_images_import_2026&scope=to_publish&only_missing=0&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('replace', false)
            ->assertJsonPath('updated_parts_count', 0);

        $part->refresh();
        $this->assertSame('90.00', (string) $part->allegro_price);
        $this->assertSame('140.00', (string) $part->ebay_price);
    }

    public function test_replace_requires_confirm_and_can_recalculate_existing_prices(): void
    {
        $part = $this->partWithRawPrices(['price' => 100, 'allegro_price' => 90, 'ebay_price' => 140]);

        $this->getJson('/tools/backfill-part-marketplace-prices?token=gps_images_import_2026&scope=to_publish&replace=1&dry_run=1&confirm=0')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('replace', false);

        $this->getJson('/tools/backfill-part-marketplace-prices?token=gps_images_import_2026&scope=to_publish&replace=1&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('replace', true)
            ->assertJsonPath('updated_parts_count', 1);

        $part->refresh();
        $this->assertSame('100.00', (string) $part->allegro_price);
        $this->assertSame('125.00', (string) $part->ebay_price);
    }

    public function test_skips_parts_without_positive_store_price(): void
    {
        $this->partWithRawPrices(['price' => null, 'allegro_price' => null, 'ebay_price' => null]);
        $this->partWithRawPrices(['price' => 0, 'allegro_price' => null, 'ebay_price' => null]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('updated_parts_count', 0)
            ->assertJsonPath('skipped_missing_store_price_count', 2);
    }

    public function test_to_publish_scope_uses_admin_parts_to_list_query(): void
    {
        $this->partWithRawPrices(['price' => 100, 'needs_listing' => false]);
        $this->partWithRawPrices(['price' => 100, 'needs_listing' => true]);

        $this->getJson($this->url.'&dry_run=1&confirm=0&diagnostics=1')
            ->assertOk()
            ->assertJsonPath('total_matching_parts_count', PartResource::adminPartsToListQuery()->count())
            ->assertJsonPath('diagnostics.current_filter_used', 'PartResource::adminPartsToListQuery(): parts.needs_listing = true')
            ->assertJsonPath('diagnostics.admin_to_publish_filter_used', 'PartResource::adminPartsToListQuery(): parts.needs_listing = true');
    }

    public function test_does_not_change_listings_stock_categories_warehouses_images_or_mappings(): void
    {
        DB::table('part_categories')->insert(['id' => 77, 'name' => 'Cat', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('storage_locations')->insert(['id' => 88, 'name' => 'A1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 77, 'channel' => 'ovoko', 'external_category_id' => 'OV-77', 'created_at' => now(), 'updated_at' => now()]);
        $part = $this->partWithRawPrices(['price' => 123.45, 'allegro_price' => null, 'ebay_price' => null, 'ovoko_price' => 55, 'category_id' => 77, 'storage_location_id' => 88, 'quantity' => 9]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => 'OV-1', 'price' => 222, 'quantity' => 3]);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'parts/a.jpg', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $listingBefore = DB::table('marketplace_listings')->get()->toJson();
        $mappingBefore = DB::table('marketplace_category_mappings')->get()->toJson();
        $imageBefore = DB::table('part_images')->get()->toJson();

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('mappings_changed', false);

        $part->refresh();
        $this->assertSame(77, $part->category_id);
        $this->assertSame(88, $part->storage_location_id);
        $this->assertSame(9, $part->quantity);
        $this->assertSame($listingBefore, DB::table('marketplace_listings')->get()->toJson());
        $this->assertSame($mappingBefore, DB::table('marketplace_category_mappings')->get()->toJson());
        $this->assertSame($imageBefore, DB::table('part_images')->get()->toJson());
    }

    public function test_ebay_price_is_rounded_to_two_decimal_places(): void
    {
        $part = $this->partWithRawPrices(['price' => 99.99, 'allegro_price' => null, 'ebay_price' => null]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('items.0.new_ebay_price', 124.99);

        $part->refresh();
        $this->assertSame('124.99', (string) $part->ebay_price);
    }

    private function partWithRawPrices(array $attributes = []): Part
    {
        $part = Part::query()->create(array_merge([
            'name' => 'Part',
            'needs_listing' => true,
            'needs_review' => false,
        ], array_diff_key($attributes, array_flip(['price', 'allegro_price', 'ovoko_price', 'ebay_price']))));

        DB::table('parts')->where('id', $part->id)->update([
            'price' => $attributes['price'] ?? null,
            'allegro_price' => $attributes['allegro_price'] ?? null,
            'ovoko_price' => $attributes['ovoko_price'] ?? null,
            'ebay_price' => $attributes['ebay_price'] ?? null,
        ]);

        return $part->fresh();
    }
}

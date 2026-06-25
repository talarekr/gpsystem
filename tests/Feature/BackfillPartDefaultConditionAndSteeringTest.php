<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillPartDefaultConditionAndSteeringTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/tools/backfill-part-default-condition-and-steering?token=gps_images_import_2026&scope=to_publish&only_missing=1';

    public function test_dry_run_does_not_persist_any_changes(): void
    {
        $part = Part::query()->create(['name' => 'Local draft', 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=1&confirm=0')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('would_update_parts_count', 1)
            ->assertJsonPath('updated_parts_count', 0);

        $part->refresh();
        $this->assertNull($part->condition_notes);
        $this->assertNull($part->vehicle_snapshot);
    }

    public function test_dry_run_reports_would_update_for_to_publish_part_with_empty_fields(): void
    {
        $part = Part::query()->create(['name' => 'Empty fields', 'condition_notes' => '', 'vehicle_snapshot' => ['steering_side' => ''], 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=1&confirm=0')
            ->assertOk()
            ->assertJsonPath('total_matching_parts_count', 1)
            ->assertJsonPath('items.0.part_id', $part->id)
            ->assertJsonPath('items.0.action', 'would_update')
            ->assertJsonPath('items.0.new_quality', 'Używany')
            ->assertJsonPath('items.0.new_steering_side', 'po lewej');
    }

    public function test_confirm_fills_only_empty_condition_notes_and_steering_side(): void
    {
        $part = Part::query()->create(['name' => 'To fill', 'vehicle_snapshot' => ['make' => 'BMW', 'steering_side' => null], 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('read_only', false)
            ->assertJsonPath('local_update', true)
            ->assertJsonPath('parts_changed', true)
            ->assertJsonPath('updated_parts_count', 1)
            ->assertJsonPath('quality_updated_count', 1)
            ->assertJsonPath('steering_updated_count', 1);

        $part->refresh();
        $this->assertSame('Używany', $part->condition_notes);
        $this->assertSame('po lewej', $part->vehicle_snapshot['steering_side']);
        $this->assertSame('BMW', $part->vehicle_snapshot['make']);
    }

    public function test_confirm_does_not_overwrite_existing_values(): void
    {
        $part = Part::query()->create(['name' => 'Already set', 'condition_notes' => 'Nowy', 'vehicle_snapshot' => ['steering_side' => 'po prawej'], 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('updated_parts_count', 0)
            ->assertJsonPath('skipped_already_set_count', 1)
            ->assertJsonPath('items.0.action', 'skipped_already_set');

        $part->refresh();
        $this->assertSame('Nowy', $part->condition_notes);
        $this->assertSame('po prawej', $part->vehicle_snapshot['steering_side']);
    }

    public function test_endpoint_excludes_parts_outside_to_publish_scope(): void
    {
        Part::query()->create(['name' => 'Outside', 'needs_listing' => false, 'needs_review' => false]);
        Part::query()->create(['name' => 'Review', 'needs_listing' => true, 'needs_review' => true]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('total_matching_parts_count', 0)
            ->assertJsonPath('updated_parts_count', 0);

        $this->assertSame(0, Part::query()->where('condition_notes', 'Używany')->count());
    }

    public function test_endpoint_does_not_change_listings_prices_stock_categories_warehouses_or_mappings(): void
    {
        DB::table('part_categories')->insert(['id' => 77, 'name' => 'Cat', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('storage_locations')->insert(['id' => 88, 'name' => 'A1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 77, 'channel' => 'ovoko', 'external_category_id' => 'OV-77', 'created_at' => now(), 'updated_at' => now()]);
        $part = Part::query()->create(['name' => 'Safe', 'category_id' => 77, 'storage_location_id' => 88, 'price' => 123.45, 'quantity' => 9, 'needs_listing' => true, 'needs_review' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => 'OV-1', 'price' => 222, 'quantity' => 3]);

        $listingBefore = DB::table('marketplace_listings')->get()->toJson();
        $mappingBefore = DB::table('marketplace_category_mappings')->get()->toJson();

        $this->getJson($this->url.'&dry_run=0&confirm=1')->assertOk();

        $part->refresh();
        $this->assertSame(77, $part->category_id);
        $this->assertSame(88, $part->storage_location_id);
        $this->assertSame('123.45', (string) $part->price);
        $this->assertSame(9, $part->quantity);
        $this->assertSame($listingBefore, DB::table('marketplace_listings')->get()->toJson());
        $this->assertSame($mappingBefore, DB::table('marketplace_category_mappings')->get()->toJson());
    }

    public function test_marketplace_write_flags_are_always_false(): void
    {
        Part::query()->create(['name' => 'Flags', 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('mappings_changed', false);
    }
}

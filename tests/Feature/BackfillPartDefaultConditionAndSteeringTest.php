<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource;
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
            ->assertJsonPath('items.0.new_quality', null)
            ->assertJsonPath('items.0.new_steering_side', 'po lewej')
            ->assertJsonPath('items.0.current_steering_side_admin_visible', false);
    }

    public function test_confirm_fills_only_empty_steering_side_and_leaves_quality_unchanged(): void
    {
        $part = Part::query()->create(['name' => 'To fill', 'vehicle_snapshot' => ['make' => 'BMW', 'steering_side' => null], 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('read_only', false)
            ->assertJsonPath('local_update', true)
            ->assertJsonPath('parts_changed', true)
            ->assertJsonPath('updated_parts_count', 1)
            ->assertJsonPath('quality_updated_count', 0)
            ->assertJsonPath('steering_updated_count', 1)
            ->assertJsonPath('fixed_steering_count', 1);

        $part->refresh();
        $this->assertNull($part->condition_notes);
        $this->assertSame('po lewej', $part->vehicle_snapshot['steering_side']);
        $this->assertSame('BMW', $part->vehicle_snapshot['make']);
    }

    public function test_confirm_updates_parts_by_id_not_key_and_does_not_throw_unknown_key_column_error(): void
    {
        $part = Part::query()->create(['id' => 7200, 'name' => 'Explicit id', 'vehicle_snapshot' => ['steering_side' => ''], 'needs_listing' => true, 'needs_review' => false]);
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'update')) {
                $queries[] = $query->sql;
            }
        });

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('errors_count', 0)
            ->assertJsonMissing(['reason' => "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'key' in 'WHERE'"]);

        $part->refresh();
        $this->assertNull($part->condition_notes);
        $this->assertSame('po lewej', $part->vehicle_snapshot['steering_side']);
        $this->assertTrue(
            collect($queries)->contains(fn (string $sql): bool => str_contains($sql, '"id" = ?') || str_contains($sql, '`id` = ?')),
            'Expected the confirm update to target parts.id.'
        );
        $this->assertFalse(
            collect($queries)->contains(fn (string $sql): bool => str_contains($sql, '"key"') || str_contains($sql, '`key`')),
            'Confirm update must not target a key column.'
        );
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

    public function test_to_publish_scope_uses_same_query_as_admin_parts_to_list(): void
    {
        Part::query()->create(['name' => 'Outside', 'needs_listing' => false, 'needs_review' => false]);
        Part::query()->create(['name' => 'Review but in admin to-list', 'needs_listing' => true, 'needs_review' => true]);
        Part::query()->create(['name' => 'Normal to-list', 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=1&confirm=0&diagnostics=1')
            ->assertOk()
            ->assertJsonPath('total_matching_parts_count', PartResource::adminPartsToListQuery()->count())
            ->assertJsonPath('diagnostics.current_filter_used', 'PartResource::adminPartsToListQuery(): parts.needs_listing = true')
            ->assertJsonPath('diagnostics.admin_to_publish_filter_used', 'PartResource::adminPartsToListQuery(): parts.needs_listing = true')
            ->assertJsonPath('diagnostics.admin_steering_field_path', 'vehicle_snapshot.steering_side')
            ->assertJsonPath('diagnostics.expected_left_steering_value', 'po lewej');
    }

    public function test_endpoint_excludes_parts_outside_to_publish_scope(): void
    {
        Part::query()->create(['name' => 'Outside', 'needs_listing' => false, 'needs_review' => false]);

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
        $part = Part::query()->create(['name' => 'Safe', 'category_id' => 77, 'storage_location_id' => 88, 'price' => 123.45, 'quantity' => 9, 'condition_notes' => '', 'vehicle_snapshot' => ['make' => 'Audi', 'steering_side' => ''], 'needs_listing' => true, 'needs_review' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => 'OV-1', 'price' => 222, 'quantity' => 3]);

        $listingBefore = DB::table('marketplace_listings')->get()->toJson();
        $mappingBefore = DB::table('marketplace_category_mappings')->get()->toJson();

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false);

        $part->refresh();
        $this->assertSame(77, $part->category_id);
        $this->assertSame(88, $part->storage_location_id);
        $this->assertSame('123.45', (string) $part->price);
        $this->assertSame(9, $part->quantity);
        $this->assertSame('Audi', $part->vehicle_snapshot['make']);
        $this->assertSame($listingBefore, DB::table('marketplace_listings')->get()->toJson());
        $this->assertSame($mappingBefore, DB::table('marketplace_category_mappings')->get()->toJson());
    }


    public function test_dry_run_treats_admin_invisible_steering_as_missing_even_when_quality_is_ok(): void
    {
        $part = Part::query()->create(['name' => 'Quality ok steering missing', 'condition_notes' => 'Używany', 'vehicle_snapshot' => [], 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=1&confirm=0&diagnostics=1')
            ->assertOk()
            ->assertJsonPath('quality_ok_count', 1)
            ->assertJsonPath('steering_admin_visible_count', 0)
            ->assertJsonPath('would_fix_steering_count', 1)
            ->assertJsonPath('fixed_steering_count', 0)
            ->assertJsonPath('items.0.part_id', $part->id);
    }

    public function test_left_side_legacy_label_is_admin_visible_and_not_rewritten(): void
    {
        $part = Part::query()->create(['name' => 'Legacy left', 'condition_notes' => 'Używany', 'vehicle_snapshot' => ['steering_side' => 'Lewa strona'], 'needs_listing' => true, 'needs_review' => false]);

        $this->getJson($this->url.'&dry_run=0&confirm=1')
            ->assertOk()
            ->assertJsonPath('updated_parts_count', 0)
            ->assertJsonPath('steering_admin_visible_count', 1)
            ->assertJsonPath('skipped_steering_already_visible_count', 1);

        $part->refresh();
        $this->assertSame('Lewa strona', $part->vehicle_snapshot['steering_side']);
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

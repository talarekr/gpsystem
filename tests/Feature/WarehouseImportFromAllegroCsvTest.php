<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WarehouseImportFromAllegroCsvTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'gps_images_import_2026';

    public function test_suggest_is_read_only_and_reports_safe_flags(): void
    {
        $path = $this->csv([['1001', 'Offer', ' A  01 ', 'ACTIVE', '7', 'https://example.test']]);
        $partId = $this->part();
        $listingId = $this->listing('1001', $partId);

        $this->getJson($this->suggestUrl($path).'&diagnostics=1')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('items.0.action', 'would_create_warehouse_and_assign')
            ->assertJsonMissing(['skipped_suspicious']);

        $this->assertDatabaseMissing('storage_locations', ['name' => 'A 01']);
        $this->assertSame(null, DB::table('parts')->where('id', $partId)->value('storage_location_id'));
        $this->assertNotNull(DB::table('marketplace_listings')->where('id', $listingId)->first());
    }

    public function test_apply_dry_run_writes_nothing(): void
    {
        $path = $this->csv([['1002', 'Offer', 'B2', 'ACTIVE', '1', '']]);
        $partId = $this->part();
        $this->listing('1002', $partId);

        $this->getJson($this->applyUrl($path).'&dry_run=1&confirm=0')->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('parts_changed', false);

        $this->assertDatabaseMissing('storage_locations', ['name' => 'B2']);
        $this->assertNull(DB::table('parts')->where('id', $partId)->value('storage_location_id'));
    }

    public function test_confirm_creates_missing_warehouse_and_assigns_part(): void
    {
        $path = $this->csv([['1003', 'Offer', 'paleta   z tyłu', 'ACTIVE', '1', '']]);
        $partId = $this->part();
        $this->listing('1003', $partId);

        $this->getJson($this->applyUrl($path).'&dry_run=0&confirm=1')->assertOk()
            ->assertJsonPath('read_only', false)
            ->assertJsonPath('warehouses_changed', true)
            ->assertJsonPath('parts_changed', true)
            ->assertJsonPath('products_changed', false)
            ->assertJsonPath('offers_changed', false)
            ->assertJsonPath('items.0.action', 'created_warehouse_and_assigned');

        $location = DB::table('storage_locations')->where('name', 'paleta z tyłu')->first();
        $this->assertNotNull($location);
        $this->assertSame($location->id, DB::table('parts')->where('id', $partId)->value('storage_location_id'));
    }

    public function test_existing_warehouse_and_uuid_external_id_are_reused_and_assigned(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $locationId = DB::table('storage_locations')->insertGetId(['name' => $uuid, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $path = $this->csv([['1004', 'Offer', $uuid, 'ACTIVE', '1', '']]);
        $partId = $this->part();
        $listingBefore = $this->listing('1004', $partId, ['price' => 123.45, 'quantity' => 9, 'status' => 'ACTIVE']);
        $before = (array) DB::table('marketplace_listings')->where('id', $listingBefore)->first();

        $this->getJson($this->applyUrl($path).'&dry_run=0&confirm=1')->assertOk()
            ->assertJsonPath('created_warehouses_count', 0)
            ->assertJsonPath('assigned_parts_count', 1)
            ->assertJsonPath('items.0.action', 'assigned_existing_warehouse')
            ->assertJsonPath('allegro_write', false)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('ebay_write', false);

        $this->assertSame($locationId, DB::table('parts')->where('id', $partId)->value('storage_location_id'));
        $this->assertEquals($before, (array) DB::table('marketplace_listings')->where('id', $listingBefore)->first());
    }

    public function test_empty_external_id_and_unmatched_offer_are_skipped(): void
    {
        $path = $this->csv([
            ['missing', 'Missing', 'strych', 'ACTIVE', '1', ''],
            ['empty', 'Empty', '', 'ACTIVE', '1', ''],
        ]);

        $this->getJson($this->applyUrl($path).'&dry_run=0&confirm=1&include_empty_external_id=1')->assertOk()
            ->assertJsonPath('skipped_unmatched_listing_count', 1)
            ->assertJsonPath('skipped_empty_external_id_count', 1)
            ->assertJsonPath('items.0.action', 'skipped_unmatched_listing')
            ->assertJsonPath('items.1.action', 'skipped_empty_external_id');

        $this->assertDatabaseMissing('storage_locations', ['name' => 'strych']);
    }

    private function csv(array $rows): string
    {
        $path = storage_path('app/testing-allegro-'.uniqid().'.csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['offer_id', 'name', 'external_id', 'status', 'stock', 'url']);
        foreach ($rows as $row) fputcsv($handle, $row);
        fclose($handle);
        return $path;
    }

    private function part(array $extra = []): int
    {
        return DB::table('parts')->insertGetId(array_merge(['name' => 'Part', 'currency' => 'PLN', 'quantity' => 1, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()], $extra));
    }

    private function listing(string $offerId, int $partId, array $extra = []): int
    {
        return DB::table('marketplace_listings')->insertGetId(array_merge(['marketplace' => 'allegro', 'part_id' => $partId, 'external_offer_id' => $offerId, 'currency' => 'PLN', 'created_at' => now(), 'updated_at' => now()], $extra));
    }

    private function suggestUrl(string $path): string
    {
        return '/tools/suggest-warehouse-import-from-allegro-csv?token='.self::TOKEN.'&path='.urlencode($path);
    }

    private function applyUrl(string $path): string
    {
        return '/tools/apply-warehouse-import-from-allegro-csv?token='.self::TOKEN.'&path='.urlencode($path);
    }
}

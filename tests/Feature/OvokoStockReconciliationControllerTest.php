<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OvokoStockReconciliationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_raw_id_diagnostics_and_paginates_to_last_page(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/parts?limit=2&page=1' => Http::response(['status_code' => 'R200', 'parts' => [
                ['id' => '10776', 'part_id' => '1', 'external_id' => 'EXT-A', 'code' => 'A'],
                ['id' => '99999', 'part_id' => '2', 'external_id' => 'EXT-B', 'code' => 'B'],
            ], 'total_count' => 3], 200),
            'api.rrr.lt/v2/get/parts?limit=2&page=2' => Http::response(['status_code' => 'R200', 'parts' => [
                ['id' => '10775', 'part_id' => '3', 'external_id' => 'EXT-C', 'car_part_id' => 'CP-C'],
            ], 'total_count' => 3], 200),
        ]);

        $this->account();
        $part = Part::query()->create(['name' => 'Lamp', 'quantity' => 1, 'status' => 'published', 'needs_listing' => false, 'needs_review' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '10776']);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation?token=gps_images_import_2026&limit=2');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ovoko_pages_fetched', 2)
            ->assertJsonPath('ovoko_limit_per_page', 2)
            ->assertJsonPath('ovoko_last_page_reached', true)
            ->assertJsonPath('ovoko_has_more', false)
            ->assertJsonPath('ovoko_api_total_count', 3)
            ->assertJsonPath('ovoko_selected_id_field', 'id')
            ->assertJsonPath('matched_active_ovoko_count', 1)
            ->assertJsonPath('would_mark_needs_review_count', 0)
            ->assertJsonFragment(['id' => '10776', 'part_id' => '1', 'external_id' => 'EXT-A', 'code' => 'A'])
            ->assertJsonFragment(['local_checked_ovoko_ids_sample' => ['10776']]);
    }

    public function test_incomplete_ovoko_range_and_zero_matches_block_live_update(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/parts?limit=2&page=1' => Http::response(['status_code' => 'R200', 'parts' => [
                ['id' => '90001', 'part_id' => '1'],
                ['id' => '90002', 'part_id' => '2'],
            ], 'total_count' => 5], 200),
        ]);

        $this->account();
        $part = Part::query()->create(['name' => 'Lamp', 'quantity' => 1, 'status' => 'published', 'needs_listing' => false, 'needs_review' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '10776']);

        $response = $this->getJson('/tools/run-ovoko-stock-reconciliation?token=gps_images_import_2026&confirm=1&limit=2&ovoko_max_pages=1');

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('would_mark_needs_review_count', null)
            ->assertJsonFragment(['ovoko_active_list_incomplete_cannot_mark_missing'])
            ->assertJsonFragment(['zero_matches_between_local_and_ovoko_check_id_mapping_before_confirm'])
            ->assertJsonFragment(['ovoko_id_mapping_not_confident']);

        $this->assertFalse($part->refresh()->needs_review);
    }

    private function account(): MarketplaceAccount
    {
        return MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_base_url' => 'https://api.rrr.lt',
            'api_credentials' => ['username' => 'u', 'password' => 'p', 'user_token' => 't'],
        ]);
    }
}

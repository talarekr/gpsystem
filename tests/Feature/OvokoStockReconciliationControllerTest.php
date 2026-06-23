<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
            ->assertJsonPath('partial_missing_in_fetched_ovoko_count', null)
            ->assertJsonPath('missing_in_ovoko_active_count', 0)
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
            ->assertJsonPath('partial_missing_in_fetched_ovoko_count', 1)
            ->assertJsonPath('missing_in_ovoko_active_count', null)
            ->assertJsonPath('would_mark_needs_review_count', null)
            ->assertJsonFragment(['ovoko_active_list_incomplete_cannot_mark_missing'])
            ->assertJsonFragment(['zero_matches_between_local_and_ovoko_check_id_mapping_before_confirm'])
            ->assertJsonFragment(['ovoko_id_mapping_not_confident']);

        $this->assertFalse($part->refresh()->needs_review);
    }

    public function test_fetch_all_ovoko_uses_max_pages_until_last_page(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/parts?limit=2&page=1' => Http::response(['status_code' => 'R200', 'parts' => [
                ['id' => '5700'],
                ['id' => '5701'],
            ], 'total_count' => 5], 200),
            'api.rrr.lt/v2/get/parts?limit=2&page=2' => Http::response(['status_code' => 'R200', 'parts' => [
                ['id' => '9000'],
                ['id' => '9001'],
            ], 'total_count' => 5], 200),
            'api.rrr.lt/v2/get/parts?limit=2&page=3' => Http::response(['status_code' => 'R200', 'parts' => [
                ['id' => '10776'],
            ], 'total_count' => 5], 200),
        ]);

        $this->account();
        $part = Part::query()->create(['name' => 'Lamp', 'quantity' => 1, 'status' => 'published', 'needs_listing' => false, 'needs_review' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '10776']);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation?token=gps_images_import_2026&limit=2&fetch_all_ovoko=1&max_pages=3');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ovoko_pages_fetched', 3)
            ->assertJsonPath('ovoko_last_page_reached', true)
            ->assertJsonPath('ovoko_has_more', false)
            ->assertJsonPath('ovoko_api_total_count', 5)
            ->assertJsonPath('ovoko_max_pages', 3)
            ->assertJsonPath('ovoko_fetch_all_requested', true)
            ->assertJsonPath('ovoko_active_ids_count', 5)
            ->assertJsonPath('matched_active_ovoko_count', 1)
            ->assertJsonPath('partial_missing_in_fetched_ovoko_count', null)
            ->assertJsonPath('missing_in_ovoko_active_count', 0)
            ->assertJsonPath('would_mark_needs_review_count', 0);
    }

    public function test_dry_run_all_fetches_full_ovoko_and_multiple_local_pages_counts_results(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/parts?limit=2&page=1' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'A1'], ['id' => 'A2']], 'total_count' => 3], 200),
            'api.rrr.lt/v2/get/parts?limit=2&page=2' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'A3']], 'total_count' => 3], 200),
        ]);
        $this->account();
        $this->partWithOvoko('A1', false);
        $this->partWithOvoko('MISSING', false);
        $this->partWithOvoko('A3', true);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-all?token=gps_images_import_2026&ovoko_limit=2&local_limit=2');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('local_update_only', false)
            ->assertJsonPath('ovoko_pages_fetched', 2)
            ->assertJsonPath('ovoko_active_ids_count', 3)
            ->assertJsonPath('local_pages_fetched', 2)
            ->assertJsonPath('local_last_page_reached', true)
            ->assertJsonPath('local_candidate_parts_count', 3)
            ->assertJsonPath('matched_active_ovoko_count', 2)
            ->assertJsonPath('missing_in_ovoko_active_count', 1)
            ->assertJsonPath('would_mark_needs_review_count', 1)
            ->assertJsonPath('already_needs_review_count', 1);
    }

    public function test_dry_run_all_stops_after_short_last_local_page(): void
    {
        Http::fake(['api.rrr.lt/v2/get/parts?limit=100&page=1' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'A1']], 'total_count' => 1], 200)]);
        $this->account();
        $this->partWithOvoko('A1', false);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-all?token=gps_images_import_2026&local_limit=2');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('local_pages_fetched', 1)
            ->assertJsonPath('local_last_page_reached', true)
            ->assertJsonPath('local_has_more', false);
    }

    public function test_dry_run_all_blocks_when_ovoko_is_incomplete(): void
    {
        Http::fake(['api.rrr.lt/v2/get/parts?limit=2&page=1' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'A1'], ['id' => 'A2']], 'total_count' => 5], 200)]);
        $this->account();
        $this->partWithOvoko('A1', false);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-all?token=gps_images_import_2026&ovoko_limit=2&max_ovoko_pages=1');

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('ovoko_last_page_reached', false)
            ->assertJsonFragment(['ovoko_active_list_incomplete_cannot_mark_missing']);
    }

    public function test_dry_run_all_blocks_when_max_local_pages_is_exceeded(): void
    {
        Http::fake(['api.rrr.lt/v2/get/parts?limit=100&page=1' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'A1'], ['id' => 'A2']], 'total_count' => 2], 200)]);
        $this->account();
        $this->partWithOvoko('A1', false);
        $this->partWithOvoko('A2', false);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-all?token=gps_images_import_2026&local_limit=1&max_local_pages=1');

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('local_pages_fetched', 1)
            ->assertJsonPath('local_has_more', true)
            ->assertJsonPath('partial_missing_in_scanned_local_count', 0)
            ->assertJsonPath('missing_in_ovoko_active_count', null)
            ->assertJsonPath('would_mark_needs_review_count', null)
            ->assertJsonFragment(['local_candidate_scan_incomplete']);
    }

    public function test_dry_run_all_blocks_zero_matches_and_counts_missing_without_writes(): void
    {
        Http::fake(['api.rrr.lt/v2/get/parts?limit=100&page=1' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'REMOTE']], 'total_count' => 1], 200)]);
        $this->account();
        $part = $this->partWithOvoko('LOCAL', false);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-all?token=gps_images_import_2026');

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('matched_active_ovoko_count', 0)
            ->assertJsonPath('missing_in_ovoko_active_count', 1)
            ->assertJsonPath('would_mark_needs_review_count', null)
            ->assertJsonFragment(['zero_matches_between_local_and_ovoko_check_id_mapping_before_confirm']);
        $this->assertFalse($part->refresh()->needs_review);
    }

    public function test_prepare_snapshot_fetches_complete_ovoko_list_and_caches_ids_without_local_writes(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/parts?limit=2&page=1' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'A1'], ['id' => 'A2']], 'total_count' => 3], 200),
            'api.rrr.lt/v2/get/parts?limit=2&page=2' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'A3']], 'total_count' => 3], 200),
        ]);
        $this->account();
        $part = $this->partWithOvoko('A1', false);

        $response = $this->getJson('/tools/prepare-ovoko-stock-reconciliation-snapshot?token=gps_images_import_2026&ovoko_limit=2&max_ovoko_pages=2');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('ovoko_api_total_count', 3)
            ->assertJsonPath('ovoko_pages_fetched', 2)
            ->assertJsonPath('ovoko_last_page_reached', true)
            ->assertJsonPath('ovoko_has_more', false)
            ->assertJsonPath('ovoko_active_ids_count', 3)
            ->assertJsonPath('blockers', []);
        $this->assertNotEmpty($response->json('snapshot_id'));
        $this->assertFalse($part->refresh()->needs_review);
        Http::assertSentCount(2);
    }

    public function test_prepare_snapshot_blocks_and_does_not_cache_incomplete_ovoko_list(): void
    {
        Http::fake(['api.rrr.lt/v2/get/parts?limit=2&page=1' => Http::response(['status_code' => 'R200', 'parts' => [['id' => 'A1'], ['id' => 'A2']], 'total_count' => 5], 200)]);
        $this->account();

        $response = $this->getJson('/tools/prepare-ovoko-stock-reconciliation-snapshot?token=gps_images_import_2026&ovoko_limit=2&max_ovoko_pages=1');

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('snapshot_id', null)
            ->assertJsonPath('ovoko_last_page_reached', false)
            ->assertJsonFragment(['ovoko_active_list_incomplete_cannot_mark_missing']);
    }

    public function test_snapshot_batch_scans_single_local_page_and_reports_next_page_without_writes_or_http_calls(): void
    {
        Http::fake();
        $this->account();
        $matched = $this->partWithOvoko('A1', false);
        $missing = $this->partWithOvoko('MISSING', false);
        $this->partWithOvoko('A3', true);
        $snapshotId = $this->putSnapshot(['A1', 'A3']);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-batch?token=gps_images_import_2026&snapshot_id='.$snapshotId.'&page=1&local_limit=2&sample_limit=5');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('snapshot_id', $snapshotId)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('local_limit', 2)
            ->assertJsonPath('local_candidate_parts_count', 2)
            ->assertJsonPath('matched_active_ovoko_count', 1)
            ->assertJsonPath('missing_in_ovoko_active_count', 1)
            ->assertJsonPath('would_mark_needs_review_count', 1)
            ->assertJsonPath('already_needs_review_count', 0)
            ->assertJsonPath('conflict_count', 0)
            ->assertJsonPath('has_more_local', true)
            ->assertJsonPath('next_page', 2)
            ->assertJsonFragment(['ovoko_external_id' => 'A1'])
            ->assertJsonFragment(['ovoko_external_id' => 'MISSING']);
        $this->assertFalse($matched->refresh()->needs_review);
        $this->assertFalse($missing->refresh()->needs_review);
        Http::assertNothingSent();
    }

    public function test_snapshot_batch_last_page_has_no_next_page(): void
    {
        Http::fake();
        $this->account();
        $this->partWithOvoko('A1', false);
        $this->partWithOvoko('A2', false);
        $snapshotId = $this->putSnapshot(['A1', 'A2']);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-batch?token=gps_images_import_2026&snapshot_id='.$snapshotId.'&page=2&local_limit=1');

        $response->assertOk()
            ->assertJsonPath('local_candidate_parts_count', 1)
            ->assertJsonPath('has_more_local', false)
            ->assertJsonPath('next_page', null);
        Http::assertNothingSent();
    }

    public function test_snapshot_batch_blocks_when_snapshot_missing_or_expired(): void
    {
        $missing = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-batch?token=gps_images_import_2026&snapshot_id=missing&page=1');
        $missing->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['ovoko_snapshot_not_found']);

        $snapshotId = $this->putSnapshot(['A1'], now()->subMinute());
        $expired = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-batch?token=gps_images_import_2026&snapshot_id='.$snapshotId.'&page=1');
        $expired->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['ovoko_snapshot_expired']);
    }

    public function test_snapshot_range_scans_limited_pages_against_snapshot(): void
    {
        Http::fake();
        $this->account();
        $this->partWithOvoko('A1', false);
        $this->partWithOvoko('MISSING', false);
        $this->partWithOvoko('A3', true);
        $snapshotId = $this->putSnapshot(['A1', 'A3']);

        $response = $this->getJson('/tools/dry-run-ovoko-stock-reconciliation-range?token=gps_images_import_2026&snapshot_id='.$snapshotId.'&from_page=1&to_page=2&local_limit=2');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('local_candidate_parts_count', 3)
            ->assertJsonPath('matched_active_ovoko_count', 2)
            ->assertJsonPath('missing_in_ovoko_active_count', 1)
            ->assertJsonPath('would_mark_needs_review_count', 1)
            ->assertJsonPath('already_needs_review_count', 1)
            ->assertJsonPath('has_more_local', false);
        Http::assertNothingSent();
    }

    private function partWithOvoko(string $externalId, bool $needsReview): Part
    {
        $part = Part::query()->create(['name' => 'Lamp '.$externalId, 'quantity' => 1, 'status' => 'published', 'needs_listing' => false, 'needs_review' => $needsReview]);
        MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => $externalId]);
        return $part;
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

    private function putSnapshot(array $activeIds, $expiresAt = null): string
    {
        $snapshotId = 'test-snapshot-'.str_replace('.', '-', (string) microtime(true));
        $expiresAt ??= now()->addHour();
        Cache::put('ovoko_stock_reconciliation_snapshot:'.$snapshotId, [
            'snapshot_id' => $snapshotId,
            'active_ids' => $activeIds,
            'created_at' => now()->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'ovoko_api_total_count' => count($activeIds),
            'ovoko_pages_fetched' => 1,
            'ovoko_last_page_reached' => true,
            'ovoko_has_more' => false,
            'ovoko_selected_id_field' => 'id',
            'ovoko_detected_id_fields' => ['id' => count($activeIds)],
        ], now()->addHour());
        return $snapshotId;
    }
}

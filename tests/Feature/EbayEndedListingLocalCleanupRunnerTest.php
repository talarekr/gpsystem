<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbayEndedListingLocalCleanupRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token']]);
    }

    public function test_dry_run_does_not_mutate_ended_listing(): void
    {
        $listing = $this->listing('ebay_de', 'active', 'mapped', 'confirmed', '389994030038');
        Http::fake(['https://api.ebay.test/buy/browse/v1/item/v1|389994030038|0' => Http::response(['itemEndDate' => now()->subDay()->toISOString()], 200)]);

        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/start', ['mode' => 'dry_run', 'batch_size' => 10, 'delay_seconds' => 0, 'confirm' => 'start-ebay-listing-status-audit-runner'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/run-next-batch')->assertOk()->assertJsonPath('ended_count', 1)->assertJsonPath('cleaned_count', 0);

        $listing->refresh();
        $this->assertSame('active', $listing->status);
        $this->assertSame('https://www.ebay.de/itm/389994030038', $listing->url);
    }

    public function test_live_cleans_only_confirmed_ended_listing_and_preserves_metadata(): void
    {
        $listing = $this->listing('ebay_de', 'active', 'mapped', 'confirmed', '111111111111', ['metadata' => ['keep' => 'yes']]);
        Http::fake(['https://api.ebay.test/buy/browse/v1/item/v1|111111111111|0' => Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'UNAVAILABLE']]], 200)]);

        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/start', ['mode' => 'live', 'batch_size' => 10, 'delay_seconds' => 0, 'confirm' => 'start-ebay-listing-status-audit-runner'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/run-next-batch')->assertOk()->assertJsonPath('cleaned_count', 1);

        $listing->refresh();
        $this->assertNull($listing->external_offer_id);
        $this->assertNull($listing->external_listing_id);
        $this->assertNull($listing->url);
        $this->assertSame('ended', $listing->status);
        $this->assertSame('stale', $listing->sync_status);
        $this->assertSame('unmatched', $listing->match_status);
        $this->assertSame('remote_ended', $listing->last_api_status);
        $this->assertSame('yes', $listing->raw_payload['metadata']['keep']);
        $this->assertSame('https://www.ebay.de/itm/111111111111', $listing->raw_payload['metadata']['previous_url']);
    }

    public function test_active_remote_listing_is_skipped(): void
    {
        $listing = $this->listing('ebay_de', 'active', 'mapped', 'confirmed', '222222222222');
        Http::fake(['https://api.ebay.test/buy/browse/v1/item/v1|222222222222|0' => Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200)]);

        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/start', ['mode' => 'live', 'batch_size' => 10, 'delay_seconds' => 0, 'confirm' => 'start-ebay-listing-status-audit-runner'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/run-next-batch')->assertOk()->assertJsonPath('active_count', 1)->assertJsonPath('cleaned_count', 0);
        $this->assertSame('active', $listing->fresh()->status);
    }

    public function test_api_error_is_skipped(): void
    {
        $listing = $this->listing('ebay_de', 'active', 'mapped', 'confirmed', '333333333333');
        Http::fake(['https://api.ebay.test/buy/browse/v1/item/v1|333333333333|0' => Http::response(['errors' => []], 500)]);

        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/start', ['mode' => 'live', 'batch_size' => 10, 'delay_seconds' => 0, 'confirm' => 'start-ebay-listing-status-audit-runner'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/run-next-batch')->assertOk()->assertJsonPath('failed_count', 1)->assertJsonPath('cleaned_count', 0);
        $this->assertNotNull($listing->fresh()->url);
    }

    public function test_non_ebay_listings_are_not_touched(): void
    {
        $part = Part::query()->create(['name' => 'Other', 'sku' => 'OTH', 'status' => 'ready']);
        $other = MarketplaceListing::query()->create(['marketplace' => 'ovoko', 'part_id' => $part->id, 'external_offer_id' => '444444444444', 'status' => 'active', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'url' => 'https://example.test/444444444444']);
        Http::fake();

        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/start', ['mode' => 'live', 'batch_size' => 10, 'delay_seconds' => 0, 'confirm' => 'start-ebay-listing-status-audit-runner'])->assertOk()->assertJsonPath('total_candidates_at_start', 0);
        $this->assertSame('active', $other->fresh()->status);
    }

    public function test_dry_run_exports_cleanup_recommended_results_as_json_and_csv(): void
    {
        $listing = $this->listing('ebay_de', 'active', 'mapped', 'confirmed', '555555555555');
        Http::fake(['https://api.ebay.test/buy/browse/v1/item/v1|555555555555|0' => Http::response(['itemEndDate' => now()->subDay()->toISOString()], 200)]);

        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/start', ['mode' => 'dry_run', 'batch_size' => 10, 'delay_seconds' => 0, 'confirm' => 'start-ebay-listing-status-audit-runner'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/run-next-batch')->assertOk()->assertJsonPath('cleanup_recommended_count', 1);

        $this->getJson('/admin/tools/ebay/listing-status-audit-runner/results?json=1&status=cleanup_recommended')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('results.0.part_id', $listing->part_id)
            ->assertJsonPath('results.0.local_url', 'https://www.ebay.de/itm/555555555555')
            ->assertJsonPath('results.0.would_cleanup', true)
            ->assertJsonPath('results.0.cleaned', false);

        $csv = $this->get('/admin/tools/ebay/listing-status-audit-runner/results.csv?status=cleanup_recommended')->assertOk()->streamedContent();
        $this->assertStringContainsString('part_id', $csv);
        $this->assertStringContainsString((string) $listing->part_id, $csv);
        $this->assertStringContainsString('https://www.ebay.de/itm/555555555555', $csv);
        $this->assertSame('https://www.ebay.de/itm/555555555555', $listing->fresh()->url);
    }

    public function test_live_exports_cleaned_results(): void
    {
        $listing = $this->listing('ebay_de', 'active', 'mapped', 'confirmed', '666666666666');
        Http::fake(['https://api.ebay.test/buy/browse/v1/item/v1|666666666666|0' => Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'UNAVAILABLE']]], 200)]);

        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/start', ['mode' => 'live', 'batch_size' => 10, 'delay_seconds' => 0, 'confirm' => 'start-ebay-listing-status-audit-runner'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-audit-runner/run-next-batch')->assertOk()->assertJsonPath('cleaned_count', 1);

        $this->getJson('/admin/tools/ebay/listing-status-audit-runner/results?json=1&status=cleaned')
            ->assertOk()
            ->assertJsonPath('results.0.part_id', $listing->part_id)
            ->assertJsonPath('results.0.cleaned', true);
    }

    public function test_results_export_empty_state_is_read_only_without_500(): void
    {
        Http::fake();

        $this->getJson('/admin/tools/ebay/listing-status-audit-runner/results?json=1&status=cleanup_recommended')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonCount(0, 'results');
    }

    private function listing(string $marketplace, string $status, string $sync, string $match, string $itemId, array $raw = []): MarketplaceListing
    {
        $part = Part::query()->create(['name' => 'Part '.$itemId, 'sku' => 'SKU'.$itemId, 'status' => 'ready']);
        return MarketplaceListing::query()->create(['marketplace' => $marketplace, 'part_id' => $part->id, 'external_offer_id' => $itemId, 'external_listing_id' => $itemId, 'external_inventory_id' => 'INV-'.$itemId, 'sku' => $part->sku, 'status' => $status, 'sync_status' => $sync, 'match_status' => $match, 'url' => 'https://www.ebay.de/itm/'.$itemId, 'raw_payload' => $raw]);
    }
}

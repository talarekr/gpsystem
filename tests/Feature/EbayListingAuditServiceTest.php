<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Services\Marketplace\EbayListingAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EbayListingAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gpsw_external_id_is_not_treated_as_ebay_item_id(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => 56, 'external_offer_id' => 'GPSW-2135', 'status' => 'active']);
        $row = app(EbayListingAuditService::class)->run('ebay_de')['results'][0];
        $this->assertSame('invalid_external_id', $row['action']);
        $this->assertSame('needs_review', $row['panel_listing_status']);
    }

    public function test_ended_item_does_not_show_active_status(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'external_listing_id' => '389994514100', 'url' => 'https://www.ebay.de/itm/389994514100', 'status' => 'ended', 'raw_payload' => ['ended_at' => '2026-05-31T22:00:00Z']]);
        $row = app(EbayListingAuditService::class)->run('ebay_de')['results'][0];
        $this->assertSame('ended', $row['panel_listing_status']);
        $this->assertSame('stale_ended_listing', $row['action']);
    }

    public function test_existing_url_of_ended_listing_is_not_green_active(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'url' => 'https://www.ebay.de/itm/389994514100', 'last_api_status' => 'ended']);
        $row = app(EbayListingAuditService::class)->run('ebay_de')['results'][0];
        $this->assertNotSame('active', $row['panel_listing_status']);
    }

    public function test_active_item_id_gives_active_status(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'external_listing_id' => '167079192011', 'url' => 'https://www.ebay.de/itm/167079192011', 'status' => 'active']);
        $row = app(EbayListingAuditService::class)->run('ebay_de')['results'][0];
        $this->assertSame('active', $row['panel_listing_status']);
        $this->assertSame('ok_active', $row['action']);
    }

    public function test_old_ended_and_new_active_same_sku_shows_high_confidence_would_update(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => 2266, 'sku' => 'GPSW-2266', 'external_listing_id' => '389994514100', 'url' => 'https://www.ebay.de/itm/389994514100', 'status' => 'ended', 'created_at' => '2026-05-31 10:00:00', 'raw_payload' => ['ended_at' => '2026-05-31T22:00:00Z']]);
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => 2266, 'sku' => 'GPSW-2266', 'external_listing_id' => '167079192011', 'url' => 'https://www.ebay.de/itm/167079192011', 'status' => 'active', 'created_at' => '2026-06-01 10:00:00', 'raw_payload' => ['published_at' => '2026-06-01T10:00:00Z']]);
        $row = app(EbayListingAuditService::class)->run('ebay_de', 1, 0, 2266)['results'][0];
        $this->assertSame('would_update_url', $row['action']);
        $this->assertSame('high', $row['confidence']);
        $this->assertSame('167079192011', $row['new_item_id']);
    }

    public function test_missing_activity_confirmation_needs_manual_review(): void
    {
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'external_listing_id' => '167065434011', 'url' => 'https://www.ebay.de/itm/167065434011', 'status' => null]);
        $row = app(EbayListingAuditService::class)->run('ebay_de')['results'][0];
        $this->assertSame('needs_manual_review', $row['action']);
    }
}

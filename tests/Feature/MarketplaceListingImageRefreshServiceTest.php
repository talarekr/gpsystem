<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\MarketplaceListingImageRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceListingImageRefreshServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ebay_de_preview_resolves_active_listing_stored_under_ebay_de_channel(): void
    {
        config(['app.url' => 'https://gps.test']);
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'code' => 'ebay_de', 'name' => 'eBay DE', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_mode' => 'read_only', 'api_credentials' => ['access_token' => 'token']]);
        $part = Part::query()->create(['name' => 'Part', 'sku' => 'GPSW-8015', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'https://cdn.example.test/8015.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $listing = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_offer_id' => 'offer-8015', 'external_listing_id' => '800303796518', 'external_inventory_id' => 'GPSW-8015', 'sku' => 'GPSW-8015', 'url' => 'https://www.ebay.de/itm/800303796518', 'status' => 'active']);

        Http::fake([
            'api.ebay.test/sell/inventory/v1/offer/offer-8015' => Http::response(['offerId' => 'offer-8015', 'listingId' => '800303796518', 'status' => 'PUBLISHED'], 200),
            'api.ebay.test/sell/inventory/v1/inventory_item/GPSW-8015' => Http::response(['product' => ['imageUrls' => ['https://old.example.test/1.jpg']]], 200),
        ]);

        $preview = app(MarketplaceListingImageRefreshService::class)->preview($part->id, 'ebay_de');

        $this->assertSame($listing->id, $preview['marketplace_listing_id']);
        $this->assertSame('800303796518', $preview['item_id']);
        $this->assertTrue($preview['local_listing_active']);
        $this->assertTrue($preview['api_confirms_active_offer']);
        $this->assertNotContains('missing_listing', $preview['blockers']);
    }

    public function test_repair_ebay_mapping_only_writes_after_seller_api_confirms_public_item(): void
    {
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'code' => 'ebay_de', 'name' => 'eBay DE', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_mode' => 'read_only', 'api_credentials' => ['access_token' => 'token']]);
        $part = Part::query()->create(['name' => 'Part', 'sku' => 'GPSW-8015', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']);

        Http::fake([
            'api.ebay.test/sell/inventory/v1/inventory_item/GPSW-8015' => Http::response(['sku' => 'GPSW-8015'], 200),
            'api.ebay.test/sell/inventory/v1/offer?sku=GPSW-8015&marketplace_id=EBAY_DE' => Http::response(['offers' => [['offerId' => 'offer-8015', 'listingId' => '800303796518', 'status' => 'PUBLISHED', 'sku' => 'GPSW-8015']]], 200),
            'api.ebay.test/sell/inventory/v1/offer/offer-8015' => Http::response(['offerId' => 'offer-8015', 'listingId' => '800303796518', 'status' => 'PUBLISHED', 'sku' => 'GPSW-8015'], 200),
            'api.ebay.test/buy/browse/v1/item/get_item_by_legacy_id?legacy_item_id=800303796518' => Http::response(['itemWebUrl' => 'https://www.ebay.de/itm/800303796518'], 200),
        ]);

        $result = app(MarketplaceListingImageRefreshService::class)->repairEbayMapping($part->id, 'ebay_de', 'https://www.ebay.de/itm/800303796518', MarketplaceListingImageRefreshService::CONFIRM);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('marketplace_listings', [
            'part_id' => $part->id,
            'marketplace' => 'ebay_de',
            'external_offer_id' => 'offer-8015',
            'external_listing_id' => '800303796518',
            'external_inventory_id' => 'GPSW-8015',
            'status' => 'active',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
        ]);
    }
}

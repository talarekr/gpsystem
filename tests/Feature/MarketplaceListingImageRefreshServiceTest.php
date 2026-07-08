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
        $this->assertSame(['ebay_de', 'ebay'], $preview['queried_marketplaces']);
        $this->assertSame(1, $preview['listings_found_count']);
        $this->assertSame($listing->id, $preview['all_candidate_listings'][0]['id']);
        $this->assertTrue($preview['all_candidate_listings'][0]['accepted']);
        $this->assertContains('selected_for_preview', $preview['all_candidate_listings'][0]['reasons']);
        $this->assertTrue($preview['local_listing_active']);
        $this->assertTrue($preview['api_confirms_active_offer']);
        $this->assertNotContains('missing_listing', $preview['blockers']);
    }


    public function test_ebay_preview_lists_all_part_candidates_without_channel_filter(): void
    {
        config(['app.url' => 'https://gps.test']);
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'code' => 'ebay_de', 'name' => 'eBay DE', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_mode' => 'read_only', 'api_credentials' => ['access_token' => 'token']]);
        $part = Part::query()->create(['name' => 'Part', 'sku' => 'GPSW-8015', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'https://cdn.example.test/8015.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $ebay = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ebay', 'external_offer_id' => 'offer-8015', 'external_listing_id' => '800303796518', 'external_inventory_id' => 'GPSW-8015', 'sku' => 'GPSW-8015', 'url' => 'https://www.ebay.de/itm/800303796518', 'status' => 'ended', 'sync_status' => 'ended']);
        $ovoko = MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_offer_id' => '11701', 'sku' => 'GPSW-8015', 'status' => 'active']);

        $preview = app(MarketplaceListingImageRefreshService::class)->preview($part->id, 'ebay_de');

        $this->assertNull($preview['marketplace_listing_id']);
        $this->assertSame(['ebay_de', 'ebay'], $preview['queried_marketplaces']);
        $this->assertSame(2, $preview['listings_found_count']);
        $this->assertEqualsCanonicalizing([$ebay->id, $ovoko->id], collect($preview['all_candidate_listings'])->pluck('id')->all());
        $ebayCandidate = collect($preview['all_candidate_listings'])->firstWhere('id', $ebay->id);
        $ovokoCandidate = collect($preview['all_candidate_listings'])->firstWhere('id', $ovoko->id);
        $this->assertContains('accepted_channel_match', $ebayCandidate['reasons']);
        $this->assertContains('rejected_local_status_not_active', $ebayCandidate['reasons']);
        $this->assertContains('rejected_marketplace_not_queried_for_channel', $ovokoCandidate['reasons']);
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

    public function test_ebay_de_apply_sends_inventory_revise_with_german_language_headers_and_image_only_payload_change(): void
    {
        config(['app.url' => 'https://gps.test', 'marketplace.external_api_writes_enabled' => true]);
        $account = MarketplaceAccount::query()->create([
            'marketplace' => 'ebay',
            'code' => 'ebay_de',
            'name' => 'eBay DE',
            'api_enabled' => true,
            'api_base_url' => 'https://api.ebay.test',
            'api_mode' => 'live',
            'api_credentials' => ['access_token' => 'token'],
            'api_settings' => ['marketplace_id' => 'EBAY_DE'],
        ]);
        $part = Part::query()->create(['name' => 'Part', 'sku' => 'GPS-8015-FOTELE', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']);
        DB::table('part_images')->insert(['part_id' => $part->id, 'path' => 'https://cdn.example.test/8015-new.jpg', 'sort_order' => 1, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace_account_id' => $account->id, 'marketplace' => 'ebay_de', 'external_offer_id' => '201864448011', 'external_listing_id' => '800303796518', 'external_inventory_id' => 'GPS-8015-FOTELE', 'sku' => 'GPS-8015-FOTELE', 'url' => 'https://www.ebay.de/itm/800303796518', 'status' => 'active']);

        Http::fake([
            'api.ebay.test/sell/inventory/v1/offer/201864448011' => Http::sequence()
                ->push(['offerId' => '201864448011', 'listingId' => '800303796518', 'status' => 'PUBLISHED', 'marketplaceId' => 'EBAY_DE', 'pricingSummary' => ['price' => ['value' => '100.00', 'currency' => 'EUR']]], 200)
                ->push(['offerId' => '201864448011', 'listingId' => '800303796518', 'status' => 'PUBLISHED'], 200),
            'api.ebay.test/sell/inventory/v1/inventory_item/GPS-8015-FOTELE' => Http::sequence()
                ->push(['product' => ['title' => 'Seat', 'imageUrls' => ['https://old.example.test/1.jpg']], 'condition' => 'USED_EXCELLENT'], 200)
                ->push(null, 204),
        ]);

        $result = app(MarketplaceListingImageRefreshService::class)->apply($part->id, 'ebay_de', MarketplaceListingImageRefreshService::CONFIRM);

        $this->assertTrue($result['ok']);
        $this->assertSame('de-DE', $result['planned_request']['content_language']);
        $this->assertSame('de-DE', $result['planned_request']['accept_language']);
        $this->assertSame('EBAY_DE', $result['planned_request']['marketplace_id']);
        $this->assertSame('de-DE', $result['apply_result']['content_language']);
        $this->assertSame('de-DE', $result['apply_result']['accept_language']);
        $this->assertSame('EBAY_DE', $result['apply_result']['marketplace_id']);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/sell/inventory/v1/inventory_item/GPS-8015-FOTELE')) {
                return false;
            }

            $payload = $request->data();

            return $request->hasHeader('Content-Language', 'de-DE')
                && $request->hasHeader('Accept-Language', 'de-DE')
                && $request->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_DE')
                && data_get($payload, 'product.title') === 'Seat'
                && data_get($payload, 'condition') === 'USED_EXCELLENT'
                && data_get($payload, 'product.imageUrls') === ['https://cdn.example.test/8015-new.jpg'];
        });
    }

}

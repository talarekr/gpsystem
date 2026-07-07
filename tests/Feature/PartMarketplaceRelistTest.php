<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Admin\PartLocalAvailabilityUpdater;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartMarketplaceRelistTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantity_zero_to_one_restores_part_keeps_links_reactivates_channels_and_does_not_duplicate_listings(): void
    {
        Http::fake([
            'https://allegro.test/*' => Http::response(['publication' => ['status' => 'ACTIVE']], 200),
            'https://ovoko.test/*' => Http::response(['status_code' => 'R200', 'msg' => 'ok'], 200),
            'https://ebay.test/*' => Http::response(['responses' => []], 200),
        ]);

        $part = Part::query()->create(['name' => 'Returned part', 'status' => 'sold', 'quantity' => 0, 'is_visible_storefront' => false]);
        $this->listing($part, 'allegro', ['external_offer_id' => 'ALG-315', 'status' => 'ended', 'url' => 'https://allegro.test/oferta/ALG-315']);
        $this->listing($part, 'ovoko', ['external_listing_id' => 'OVO-315', 'status' => 'inactive', 'url' => 'https://ovoko.test/OVO-315']);
        $this->listing($part, 'ebay_de', ['external_offer_id' => 'EBAY-315', 'external_listing_id' => 'ITEM-315', 'status' => 'ended', 'url' => 'https://ebay.test/itm/ITEM-315']);

        $before = MarketplaceListing::query()->where('part_id', $part->id)->count();

        app(PartLocalAvailabilityUpdater::class)->update($part, 1);

        $part->refresh()->load('marketplaceListings');
        $this->assertSame('ready', $part->status);
        $this->assertSame(1, $part->quantity);
        $this->assertTrue((bool) $part->is_visible_storefront);
        $this->assertSame($before, MarketplaceListing::query()->where('part_id', $part->id)->count());
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => 'ALG-315']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ovoko', 'external_listing_id' => 'OVO-315']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_listing_id' => 'ITEM-315']);

        $rows = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part));
        $this->assertSame('W sprzedaży', $part->adminStatusLabel());
        $this->assertSame('https://allegro.test/oferta/ALG-315', $rows->firstWhere('key', 'allegro')['url']);
        $this->assertSame('https://ovoko.test/OVO-315', $rows->firstWhere('key', 'ovoko')['url']);
        $this->assertSame('https://ebay.test/itm/ITEM-315', $rows->firstWhere('key', 'ebay')['url']);
        Http::assertSentCount(3);
    }

    public function test_admin_relist_dry_run_and_apply_are_safe_for_one_part(): void
    {
        Http::fake([
            'https://allegro.test/*' => Http::response(['publication' => ['status' => 'ACTIVE']], 200),
            'https://ovoko.test/*' => Http::response(['status_code' => 'R200', 'msg' => 'ok'], 200),
            'https://ebay.test/*' => Http::response(['responses' => []], 200),
        ]);
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret']));
        $part = Part::query()->create(['name' => 'Returned part', 'status' => 'sold', 'quantity' => 0]);
        $this->listing($part, 'allegro', ['external_offer_id' => 'ALG-1']);

        $this->getJson("/admin/tools/marketplace/relist-part?part_id={$part->id}")
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('marketplace_listings.0.qualifies_for_relist', true);
        Http::assertNothingSent();

        $this->getJson("/admin/tools/marketplace/relist-part?part_id={$part->id}&apply=1")
            ->assertOk()
            ->assertJsonPath('apply', true)
            ->assertJsonPath('duplicate_guard.created_new_marketplace_listings', 0);
        $this->assertSame(1, MarketplaceListing::query()->where('part_id', $part->id)->count());
    }


    public function test_ebay_resolver_prefers_new_published_listing_over_historical_ended_link(): void
    {
        $part = Part::query()->create(['name' => 'Relisted eBay part', 'status' => 'ready', 'quantity' => 1, 'ebay_price' => 123.45]);
        $this->listing($part, 'ebay_de', [
            'external_offer_id' => 'OLD-OFFER-886',
            'external_listing_id' => 'OLD-ITEM-886',
            'status' => 'ended',
            'sync_status' => 'ended',
            'last_api_status' => 'ended',
            'url' => 'https://www.ebay.de/itm/OLD-ITEM-886',
        ]);
        $newListing = $this->listing($part, 'ebay_de', [
            'external_offer_id' => '201340167011',
            'external_listing_id' => '800300579197',
            'status' => 'published',
            'sync_status' => 'published',
            'last_api_status' => null,
            'url' => 'https://www.ebay.de/itm/800300579197',
            'quantity' => 1,
        ]);

        $part->refresh()->load('marketplaceListings');
        $row = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part))->firstWhere('key', 'ebay');

        $this->assertSame('W sprzedaży', $part->adminStatusLabel());
        $this->assertSame('✓', $row['display_icon']);
        $this->assertSame('check', $row['icon']);
        $this->assertTrue($row['listed']);
        $this->assertTrue($row['is_active']);
        $this->assertSame('ebay_active_with_inventory', $row['reason']);
        $this->assertSame('201340167011', $row['external_offer_id']);
        $this->assertSame('https://www.ebay.de/itm/800300579197', $row['url']);
        $this->assertDatabaseHas('marketplace_listings', ['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_listing_id' => 'OLD-ITEM-886', 'status' => 'ended']);
        $this->assertDatabaseHas('marketplace_listings', ['id' => $newListing->id, 'part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_offer_id' => '201340167011', 'external_listing_id' => '800300579197', 'url' => 'https://www.ebay.de/itm/800300579197', 'status' => 'published']);
    }

    private function listing(Part $part, string $marketplace, array $attrs): MarketplaceListing
    {
        $base = $marketplace === 'allegro' ? 'https://allegro.test' : ($marketplace === 'ovoko' ? 'https://ovoko.test' : 'https://ebay.test');
        $account = MarketplaceAccount::query()->create(['marketplace' => $marketplace, 'code' => uniqid($marketplace), 'name' => $marketplace, 'api_enabled' => true, 'api_base_url' => $base, 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token', 'username' => 'u', 'password' => 'p', 'user_token' => 't'], 'api_settings' => ['marketplace_id' => 'EBAY_DE']]);
        return MarketplaceListing::query()->create(array_merge(['marketplace' => $marketplace, 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'status' => 'ended', 'sync_status' => 'ended', 'match_status' => 'mapped', 'sku' => 'SKU-'.$part->id], $attrs));
    }
}

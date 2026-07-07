<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\AllegroListingStatusRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroListingStatusRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_promotes_pending_listing_to_active_when_allegro_api_confirms_active_with_stock(): void
    {
        $account = MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_credentials' => ['access_token' => 'token'],
        ]);
        $part = Part::query()->create(['name' => 'Allegro pending part', 'sku' => 'ALG-PENDING', 'quantity' => 1, 'status' => 'ready']);
        $listing = MarketplaceListing::query()->create([
            'marketplace_account_id' => $account->id,
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => '18741244685',
            'external_listing_id' => '18741244685',
            'url' => 'https://allegro.pl/oferta/18741244685',
            'status' => 'publication_pending',
            'sync_status' => 'published',
            'match_status' => 'matched',
            'last_api_status' => null,
            'last_error' => null,
        ]);

        Http::fake([
            'https://api.allegro.test/sale/product-offers/18741244685' => Http::response([
                'id' => '18741244685',
                'publication' => ['status' => 'ACTIVE'],
                'stock' => ['available' => 1],
                'sellingMode' => ['format' => 'BUY_NOW', 'price' => ['amount' => '100', 'currency' => 'PLN']],
            ], 200),
        ]);

        $before = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'allegro');
        $this->assertFalse($before['is_active']);
        $this->assertSame('allegro_not_active', $before['reason']);

        $result = app(AllegroListingStatusRefreshService::class)->refresh($listing);

        $this->assertTrue($result['ok']);
        $this->assertSame(['before' => 'publication_pending', 'after' => 'active'], $result['changes']['status']);
        $this->assertDatabaseHas('marketplace_listings', [
            'id' => $listing->id,
            'status' => 'active',
            'last_api_status' => 'ACTIVE',
            'last_error' => null,
            'url' => 'https://allegro.pl/oferta/18741244685',
        ]);
        $this->assertDatabaseHas('parts', ['id' => $part->id, 'status' => 'ready', 'quantity' => 1]);

        $after = collect(app(PartMarketplaceStatusResolver::class)->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'allegro');
        $this->assertTrue($after['is_active']);
        $this->assertTrue($after['has_link']);
        $this->assertSame('check', $after['icon']);
        $this->assertSame('✓', $after['display_icon']);
        $this->assertSame('https://allegro.pl/oferta/18741244685', $after['url']);
    }
}

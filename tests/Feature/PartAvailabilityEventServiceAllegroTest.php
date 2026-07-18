<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\PartAvailabilityEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartAvailabilityEventServiceAllegroTest extends TestCase
{
    use RefreshDatabase;

    public function test_natural_allegro_sale_skips_outbound_end_and_reconciles_source_listing(): void
    {
        Http::fake(['https://allegro.test/sale/product-offers/17078838487' => Http::response(['publication' => ['status' => 'ENDED', 'endedBy' => 'USER'], 'stock' => ['available' => 0], 'sellingMode' => ['format' => 'BUY_NOW'], 'archived' => false], 200)]);
        [$part, $listing] = $this->allegroListing('17078838487', 1, null);

        $result = app(PartAvailabilityEventService::class)->sold(['source_channel' => 'allegro', 'part_id' => $part->id, 'offer_id' => '17078838487', 'source_order_id' => '1c1dfc00-82b3-11f1-8316-8b797f862923', 'source_order_item_id' => '1c160cc0-82b3-11f1-8316-8b797f862923']);

        $this->assertTrue($result['ok']);
        $listing->refresh();
        $this->assertSame('ended', $listing->status);
        $this->assertSame(0, $listing->quantity);
        $this->assertSame('success', $listing->last_api_status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH' && data_get($request->data(), 'publication.status') === 'ENDED');
        $this->assertDatabaseHas('marketplace_sync_logs', ['action' => 'allegro_source_sale_reconcile', 'status' => 'success']);
    }

    public function test_ebay_source_sale_still_uses_source_channel_skip_without_requests(): void
    {
        Http::fake();
        $part = Part::query()->create(['name' => 'Ebay sold', 'status' => 'ready', 'quantity' => 1]);
        MarketplaceListing::query()->create(['marketplace' => 'ebay_de', 'part_id' => $part->id, 'sku' => 'SKU-1', 'quantity' => 1, 'status' => 'active']);

        app(PartAvailabilityEventService::class)->sold(['source_channel' => 'ebay_de', 'part_id' => $part->id, 'sku' => 'SKU-1', 'source_order_item_id' => 'ebay-item-1']);

        Http::assertSentCount(0);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'ebay', 'action' => 'skip_source_channel', 'status' => 'skipped']);
    }

    public function test_allegro_restore_updates_stock_before_activation_and_requires_confirm_get(): void
    {
        Http::fakeSequence('https://allegro.test/sale/product-offers/17078838487')
            ->push(['publication' => ['status' => 'ENDED'], 'stock' => ['available' => 0], 'sellingMode' => ['format' => 'BUY_NOW'], 'archived' => false], 200)
            ->push(['stock' => ['available' => 1]], 200)
            ->push(['publication' => ['status' => 'ACTIVE']], 200)
            ->push(['publication' => ['status' => 'ACTIVE'], 'stock' => ['available' => 1], 'sellingMode' => ['format' => 'BUY_NOW'], 'archived' => false], 200);
        [$part, $listing] = $this->allegroListing('17078838487', 0, 'ended');

        app(PartAvailabilityEventService::class)->restored(['source_channel' => 'manual_stock_change', 'part_id' => $part->id]);

        $listing->refresh();
        $this->assertSame('active', $listing->status);
        $this->assertSame(1, $listing->quantity);
        $methods = [];
        Http::assertSent(function ($request) use (&$methods) { $methods[] = $request->method().':'.(data_get($request->data(), 'stock.available') ?? data_get($request->data(), 'publication.status') ?? 'GET'); return true; });
        $this->assertSame(['GET:GET', 'PATCH:1', 'PATCH:ACTIVE', 'GET:GET'], $methods);
    }

    public function test_allegro_restore_skips_writes_when_already_active_with_stock(): void
    {
        Http::fake(['https://allegro.test/sale/product-offers/17078838487' => Http::response(['publication' => ['status' => 'ACTIVE'], 'stock' => ['available' => 1], 'sellingMode' => ['format' => 'BUY_NOW'], 'archived' => false], 200)]);
        [$part] = $this->allegroListing('17078838487', 1, 'active');

        app(PartAvailabilityEventService::class)->restored(['source_channel' => 'manual_stock_change', 'part_id' => $part->id]);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_allegro_restore_stock_update_failure_does_not_activate_or_mark_active(): void
    {
        Http::fakeSequence('https://allegro.test/sale/product-offers/17078838487')
            ->push(['publication' => ['status' => 'ENDED'], 'stock' => ['available' => 0], 'sellingMode' => ['format' => 'BUY_NOW'], 'archived' => false], 200)
            ->push(['errors' => [['code' => 'StockError']]], 422);
        [$part, $listing] = $this->allegroListing('17078838487', 0, 'ended');

        app(PartAvailabilityEventService::class)->restored(['source_channel' => 'manual_stock_change', 'part_id' => $part->id]);

        $listing->refresh();
        $this->assertSame('ended', $listing->status);
        $this->assertSame('error', $listing->last_api_status);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => data_get($request->data(), 'publication.status') === 'ACTIVE');
    }

    private function allegroListing(string $offerId, int $quantity, ?string $status): array
    {
        $part = Part::query()->create(['name' => 'HGF4483', 'status' => $quantity > 0 ? 'ready' : 'sold', 'quantity' => $quantity]);
        $account = MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Allegro', 'api_enabled' => true, 'api_base_url' => 'https://allegro.test', 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token']]);
        $listing = MarketplaceListing::query()->create(['marketplace' => 'allegro', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'external_offer_id' => $offerId, 'sku' => 'HGF4483', 'quantity' => $quantity, 'status' => $status, 'sync_status' => 'mapped']);

        return [$part, $listing];
    }
}

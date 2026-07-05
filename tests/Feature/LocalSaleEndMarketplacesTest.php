<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalSaleEndMarketplacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_shows_storefront_and_marketplaces_without_api_write(): void
    {
        Http::fake();
        $this->actingAs($this->user());
        $part = Part::query()->create(['name' => 'Local sold part', 'status' => 'ready', 'quantity' => 1, 'needs_listing' => false]);
        $this->listing($part, 'allegro', ['external_offer_id' => 'ALG-1']);
        $this->listing($part, 'ovoko', ['external_listing_id' => 'OVO-1']);
        $this->listing($part, 'ebay_de', ['external_offer_id' => 'EBAY-1']);

        $response = $this->getJson("/admin/tools/parts/{$part->id}/local-sale-end-marketplaces-dry-run");

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('storefront_available', true)
            ->assertJsonCount(3, 'marketplace_listings');
        Http::assertNothingSent();
        $this->assertSame(0, MarketplaceSyncLog::query()->count());
    }

    public function test_apply_requires_confirm(): void
    {
        $this->actingAs($this->user());
        $part = Part::query()->create(['name' => 'Confirm part', 'status' => 'ready', 'quantity' => 1]);

        $this->getJson("/admin/tools/parts/{$part->id}/local-sale-end-marketplaces-apply")
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_apply_sells_one_part_ends_only_its_listings_logs_and_is_idempotent(): void
    {
        $this->actingAs($this->user());
        Http::fake([
            'https://allegro.test/*' => Http::response(['publication' => ['status' => 'ENDED']], 200),
            'https://ovoko.test/*' => Http::response(['status_code' => 'R200', 'msg' => 'ok'], 200),
            'https://ebay.test/*' => Http::response([], 204),
        ]);
        $part = Part::query()->create(['name' => 'Sold part', 'status' => 'ready', 'quantity' => 1, 'needs_listing' => false, 'is_visible_storefront' => true]);
        $other = Part::query()->create(['name' => 'Other part', 'status' => 'ready', 'quantity' => 1]);
        $this->listing($part, 'allegro', ['external_offer_id' => 'ALG-2']);
        $this->listing($part, 'ovoko', ['external_listing_id' => 'OVO-2']);
        $this->listing($part, 'ebay_de', ['external_offer_id' => 'EBAY-2']);
        $this->listing($other, 'allegro', ['external_offer_id' => 'ALG-OTHER']);

        $url = "/admin/tools/parts/{$part->id}/local-sale-end-marketplaces-apply?confirm=local-sale-end-marketplaces";
        $this->getJson($url)->assertOk()
            ->assertJsonPath('local_product_sold', true)
            ->assertJsonPath('storefront_available', false)
            ->assertJsonCount(3, 'ended_marketplaces')
            ->assertJsonPath('marketplace_write', true);

        $part->refresh();
        $this->assertSame('sold', $part->status);
        $this->assertSame('local_sale', $part->sale_source);
        $this->assertNotNull($part->sold_at);
        $this->assertSame(0, $part->quantity);
        $this->assertFalse((bool) $part->is_visible_storefront);
        $this->assertFalse(Part::query()->whereKey($part->id)->storefrontVisible()->exists());
        $this->assertTrue(Part::query()->whereKey($other->id)->storefrontVisible()->exists());
        $this->assertSame(3, MarketplaceSyncLog::query()->where('action', 'local_sale_end_listing')->count());
        $this->assertSame(3, MarketplaceListing::query()->where('part_id', $part->id)->where('status', 'ended')->count());

        $this->getJson($url)->assertOk()->assertJsonCount(0, 'ended_marketplaces');
        $this->assertSame(3, MarketplaceSyncLog::query()->where('action', 'local_sale_end_listing')->count());
    }

    public function test_missing_external_id_blocks_api_call_and_marketplace_error_does_not_rollback_sale(): void
    {
        $this->actingAs($this->user());
        Http::fake(['https://allegro.test/*' => Http::response(['error' => 'boom'], 500)]);
        $part = Part::query()->create(['name' => 'Partial failure', 'status' => 'ready', 'quantity' => 1, 'needs_listing' => false]);
        $this->listing($part, 'allegro', ['external_offer_id' => 'ALG-FAIL']);
        $this->listing($part, 'ovoko', []);

        $this->getJson("/admin/tools/parts/{$part->id}/local-sale-end-marketplaces-apply?confirm=local-sale-end-marketplaces")
            ->assertOk()
            ->assertJsonCount(1, 'failed_marketplaces')
            ->assertJsonPath('blockers.0.blocker', 'missing_external_id');

        $part->refresh();
        $this->assertSame('sold', $part->status);
        $this->assertSame('local_sale', $part->sale_source);
        $this->assertNotNull($part->sold_at);
        $this->assertSame(1, MarketplaceSyncLog::query()->where('status', 'error')->count());
        Http::assertSentCount(1);
    }


    public function test_local_sale_source_label_is_available_for_sold_parts(): void
    {
        $this->actingAs($this->user());
        Http::fake();
        $part = Part::query()->create(['name' => 'Label source part', 'status' => 'ready', 'quantity' => 1]);

        $this->getJson("/admin/tools/parts/{$part->id}/local-sale-end-marketplaces-apply?confirm=local-sale-end-marketplaces")
            ->assertOk();

        $part->refresh();
        $this->assertSame('local_sale', $part->sale_source);
        $this->assertSame('Sprzedaż lokalna', Part::saleSourceLabel($part->sale_source));
    }

    public function test_sold_parts_source_labels_are_human_readable(): void
    {
        $this->assertSame('Ręczna zmiana stanu magazynowego', Part::saleSourceLabel('Manual_stock_change'));
        $this->assertSame('Ręczna zmiana stanu magazynowego', Part::saleSourceLabel('manual_stock_change'));
        $this->assertSame('Allegro', Part::saleSourceLabel('allegro'));
        $this->assertSame('eBay', Part::saleSourceLabel('eBay'));
        $this->assertSame('Ovoko', Part::saleSourceLabel('ovoko'));
    }

    private function user(): User
    {
        return User::query()->create(['name' => 'Admin', 'email' => uniqid('admin').'@example.test', 'password' => 'secret']);
    }

    private function listing(Part $part, string $marketplace, array $attrs): MarketplaceListing
    {
        $base = match (true) {
            $marketplace === 'allegro' => 'https://allegro.test',
            $marketplace === 'ovoko' => 'https://ovoko.test',
            default => 'https://ebay.test',
        };
        $account = MarketplaceAccount::query()->create(['marketplace' => $marketplace, 'code' => $marketplace, 'name' => $marketplace, 'api_enabled' => true, 'api_base_url' => $base, 'api_mode' => 'live', 'api_credentials' => ['access_token' => 'token', 'username' => 'u', 'password' => 'p', 'user_token' => 't'], 'api_settings' => ['marketplace_id' => 'EBAY_DE']]);

        return MarketplaceListing::query()->create(array_merge(['marketplace' => $marketplace, 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'status' => 'active', 'sync_status' => 'mapped', 'sku' => 'SKU-'.$part->id], $attrs));
    }
}

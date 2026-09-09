<?php

namespace Tests\Feature;

use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JarekGearboxEbayBulkPricePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_calculates_seven_percent_and_excludes_parts(): void
    {
        Http::fake();
        $eligible = $this->gearbox([
            'price' => 400,
            'ebay_offer_id' => 'offer-1',
            'ebay_listing_id' => 'item-1',
            'ebay_inventory_sku' => 'JAREK-1',
            'ebay_status' => 'published',
            'ebay_payload_snapshot' => ['marketplaceId' => 'EBAY_DE', 'pricingSummary' => ['price' => ['value' => '100.00', 'currency' => 'EUR']]],
        ]);
        $withoutListing = $this->gearbox(['allegro_offer_id' => '2', 'price' => 200]);
        $zero = $this->gearbox([
            'allegro_offer_id' => '3',
            'price' => 0,
            'ebay_offer_id' => 'offer-3',
            'ebay_inventory_sku' => 'JAREK-3',
            'ebay_status' => 'published',
            'ebay_payload_snapshot' => ['pricingSummary' => ['price' => ['value' => 0, 'currency' => 'EUR']]],
        ]);
        Part::query()->create(['name' => 'Ordinary Part', 'price' => 100, 'ebay_price' => 100]);
        $before = JarekGearbox::query()->get()->map->getAttributes()->all();

        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-preview?percent=7');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('external_api_requests', false)
            ->assertJsonPath('percent', 7)
            ->assertJsonPath('total_jarek_products', 3)
            ->assertJsonPath('products_with_ebay_listing', 2)
            ->assertJsonPath('products_without_ebay_listing', 1)
            ->assertJsonPath('products_eligible_for_price_increase', 1)
            ->assertJsonPath('products_skipped', 2)
            ->assertJsonPath('total_old_price', 100)
            ->assertJsonPath('total_new_price', 107)
            ->assertJsonPath('total_difference', 7)
            ->assertJsonPath('sample_products.0.current_local_price', 400)
            ->assertJsonPath('sample_products.0.current_ebay_price', 100)
            ->assertJsonPath('sample_products.0.new_price', 107)
            ->assertJsonPath('sample_products.0.revise_would_be_needed', true)
            ->assertJsonPath('sample_products.1.skipped_reasons.0', 'no_ebay_listing')
            ->assertJsonFragment(['non_positive_price']);

        $this->assertSame($before, JarekGearbox::query()->get()->map->getAttributes()->all());
        $this->assertDatabaseHas('parts', ['name' => 'Ordinary Part', 'price' => 100]);
        Http::assertNothingSent();
        $eligible->refresh();
        $withoutListing->refresh();
        $zero->refresh();
    }

    public function test_preview_separates_de_and_fr_and_marks_missing_ebay_price_for_fetch(): void
    {
        $this->gearbox([
            'ebay_offer_id' => 'fr-offer',
            'ebay_inventory_sku' => 'JAREK-FR',
            'ebay_status' => 'published',
            'ebay_payload_snapshot' => ['marketplaceId' => 'EBAY_FR'],
        ]);

        $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-preview?percent=7')
            ->assertOk()
            ->assertJsonPath('ebay_channel_summary.ebay_de', 0)
            ->assertJsonPath('ebay_channel_summary.ebay_fr', 1)
            ->assertJsonPath('products_eligible_for_price_increase', 0)
            ->assertJsonPath('skipped_reasons.needs_ebay_price_fetch', 1);
    }

    public function test_apply_requires_confirmation_snapshot_canary_and_enabled_ebay(): void
    {
        $url = '/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply';
        $this->withoutMiddleware()->postJson($url, ['percent' => 7])->assertForbidden()->assertJsonPath('marketplace_write', false);
        $this->withoutMiddleware()->postJson($url, ['percent' => 7, 'confirm' => 'INCREASE_JAREK_EBAY_PRICES_7_PERCENT'])->assertStatus(422);
        $this->withoutMiddleware()->postJson($url, ['percent' => 7, 'confirm' => 'INCREASE_JAREK_EBAY_PRICES_7_PERCENT', 'snapshot_id' => 'accepted'])->assertStatus(422);

        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'api_enabled' => false]);
        $this->withoutMiddleware()->postJson($url, [
            'percent' => 7,
            'confirm' => 'INCREASE_JAREK_EBAY_PRICES_7_PERCENT',
            'snapshot_id' => 'accepted',
            'limit' => 5,
        ])->assertStatus(409)->assertJsonPath('error', 'eBay write connection is disabled')->assertJsonPath('marketplace_write', false);
    }

    private function gearbox(array $attributes = []): JarekGearbox
    {
        return JarekGearbox::query()->create(array_merge([
            'source_account' => 'jarek',
            'allegro_account' => 'jarek',
            'allegro_offer_id' => '1',
            'title' => 'Jarek product',
            'currency' => 'PLN',
            'quantity' => 1,
        ], $attributes));
    }
}

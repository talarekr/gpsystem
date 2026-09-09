<?php

namespace Tests\Feature;

use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\MarketplaceSyncLog;
use Tests\TestCase;

class JarekGearboxEbayPriceFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'secret']]);
    }

    public function test_fetch_preview_only_gets_offer_and_never_writes_locally(): void
    {
        $gearbox = $this->gearbox(['price' => 999, 'ebay_offer_id' => 'offer/1', 'ebay_inventory_sku' => 'JAREK-1']);
        $before = $gearbox->getAttributes();
        Http::fake(['https://api.ebay.test/sell/inventory/v1/offer/offer%2F1' => Http::response(['offerId' => 'offer/1', 'sku' => 'JAREK-1', 'marketplaceId' => 'EBAY_DE', 'status' => 'PUBLISHED', 'pricingSummary' => ['price' => ['value' => '100.00', 'currency' => 'EUR']]], 200, ['x-ebay-c-request-id' => 'request-1'])]);

        $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-price-fetch-preview?limit=20')
            ->assertOk()->assertJsonPath('marketplace_write', false)->assertJsonPath('external_api_requests', true)->assertJsonPath('local_write', false)
            ->assertJsonPath('prices_fetched_from_ebay_count', 1)->assertJsonPath('products.0.current_ebay_price', 100)
            ->assertJsonPath('products.0.proposed_new_price', 107)->assertJsonPath('products.0.ebay_request_id', 'request-1');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/sell/inventory/v1/offer/offer%2F1'));
        $this->assertSame($before, $gearbox->fresh()->getAttributes());
    }

    public function test_cache_apply_requires_confirm_and_only_updates_snapshot(): void
    {
        $gearbox = $this->gearbox(['price' => 555, 'quantity' => 7, 'title' => 'Untouched', 'ebay_offer_id' => 'offer-2', 'ebay_inventory_sku' => 'JAREK-2', 'ebay_payload_snapshot' => ['preserved' => true]]);
        Http::fake(['*' => Http::response(['status' => 'PUBLISHED', 'marketplaceId' => 'EBAY_DE', 'pricingSummary' => ['price' => ['value' => '10.00', 'currency' => 'EUR']]])]);
        $url = '/admin/tools/jarek-gearboxes/ebay-price-fetch-cache-apply';
        $this->withoutMiddleware()->postJson($url, ['channel' => 'ebay_de'])->assertForbidden();
        $this->withoutMiddleware()->postJson($url, ['channel' => 'ebay_de', 'confirm' => 'FETCH_JAREK_EBAY_PRICES_READ_ONLY_CACHE', 'limit' => 1])
            ->assertOk()->assertJsonPath('marketplace_write', false)->assertJsonPath('local_write', true);

        $gearbox->refresh();
        $this->assertSame('555.00', $gearbox->price);
        $this->assertSame(7, $gearbox->quantity);
        $this->assertSame('Untouched', $gearbox->title);
        $this->assertTrue(data_get($gearbox->ebay_payload_snapshot, 'preserved'));
        $this->assertSame('10.00', data_get($gearbox->ebay_payload_snapshot, '_jarek_price_fetch.pricingSummary.price.value'));
        $this->assertDatabaseHas('marketplace_sync_logs', ['action' => 'jarek_gearboxes_ebay_price_fetch_cache']);
    }

    public function test_fetch_runner_exposes_spa_safe_diagnostics_and_backend_urls(): void
    {
        $response = $this->withoutMiddleware()->get('/admin/tools/jarek-gearboxes/ebay-price-fetch-runner');

        $response->assertOk()
            ->assertSee('id="fetch-runner-start"', false)
            ->assertSee('Debug runnera')
            ->assertSee('Test dry-run request')
            ->assertSee(route('admin.tools.jarek-gearboxes.ebay-price-fetch-preview'), false)
            ->assertSee(route('admin.tools.jarek-gearboxes.ebay-price-fetch-cache-apply'), false)
            ->assertSee('DOMContentLoaded')
            ->assertSee('livewire:navigated')
            ->assertSee("document.addEventListener('click'", false)
            ->assertSee("reflectSelection('Start clicked')", false)
            ->assertSee('last_error_type')
            ->assertSee('active_request_id')
            ->assertSee('request_in_flight')
            ->assertSee('abort_reason')
            ->assertSee('aborted_at')
            ->assertSee('elapsed_ms')
            ->assertSee('timeout_ms')
            ->assertSee('duplicate_start_blocked')
            ->assertSee('batch_started')
            ->assertSee('batch_finished')
            ->assertSee('retry_count')
            ->assertSee('REQUEST_TIMEOUT_MS = 180000', false)
            ->assertSee("id('action').textContent = 'Runner already running'", false)
            ->assertSee("abortActive('user_stop')", false)
            ->assertSee("abortActive('pause')", false)
            ->assertSee("error.abortedBy === 'timeout'", false)
            ->assertSee("initialize (request preserved)", false)
            ->assertSee('value="50"', false)
            ->assertSee('max="100"', false);

        $html = $response->getContent();
        $this->assertStringNotContainsString("runtime.aborter?.abort()", $html);
        $this->assertStringNotContainsString("30000", $html);
        $this->assertStringNotContainsString('>Run All<', $html);
    }

    public function test_fetch_and_cache_endpoints_accept_limit_one_hundred_but_cache_still_requires_confirm(): void
    {
        Http::fake();

        $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-price-fetch-preview?channel=ebay_de&limit=100&offset=0')
            ->assertOk()
            ->assertJsonPath('local_write', false)
            ->assertJsonPath('marketplace_write', false);

        $cacheUrl = '/admin/tools/jarek-gearboxes/ebay-price-fetch-cache-apply';
        $this->withoutMiddleware()->postJson($cacheUrl, ['channel' => 'ebay_de', 'limit' => 100])
            ->assertForbidden();
        $this->withoutMiddleware()->postJson($cacheUrl, [
            'channel' => 'ebay_de',
            'limit' => 100,
            'confirm' => 'FETCH_JAREK_EBAY_PRICES_READ_ONLY_CACHE',
            'marketplace_write' => false,
        ])->assertOk()
            ->assertJsonPath('local_write', true)
            ->assertJsonPath('marketplace_write', false);
    }

    public function test_price_apply_endpoint_keeps_five_item_canary_limit(): void
    {
        $this->withoutMiddleware()->postJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply', [
            'percent' => 7,
            'confirm' => 'INCREASE_JAREK_EBAY_PRICES_7_PERCENT',
            'snapshot_id' => 'not-used-because-limit-is-rejected-first',
            'limit' => 6,
        ])->assertStatus(422)
            ->assertJsonPath('error', 'canary limit between 1 and 5 is required')
            ->assertJsonPath('marketplace_write', false);
    }

    public function test_canary_apply_updates_only_offer_price_and_preserves_local_product(): void
    {
        config()->set('marketplace.external_api_writes_enabled', true);
        config()->set('marketplace.jarek_ebay_price_apply_enabled', true);
        $gearbox = $this->gearbox(['price' => 9999, 'quantity' => 8, 'title' => 'Untouched', 'ebay_offer_id' => 'offer-4', 'ebay_listing_id' => 'listing-4', 'ebay_inventory_sku' => 'JAREK-4', 'ebay_payload_snapshot' => ['marketplaceId' => 'EBAY_DE', '_jarek_price_fetch' => ['fetched_at' => now()->toIso8601String(), 'pricingSummary' => ['price' => ['value' => '100.00', 'currency' => 'EUR']]]]]);
        $preview = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-preview?percent=7')->json();
        Http::fakeSequence()->push(['offerId' => 'offer-4', 'sku' => 'JAREK-4', 'marketplaceId' => 'EBAY_DE', 'availableQuantity' => 3, 'listingDescription' => 'Keep me', 'pricingSummary' => ['price' => ['value' => '100.00', 'currency' => 'EUR']]], 200)->push([], 204, ['x-ebay-c-request-id' => 'write-1']);

        $this->withoutMiddleware()->postJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply', ['percent' => 7, 'channel' => 'ebay_de', 'confirm' => 'INCREASE_JAREK_EBAY_PRICES_7_PERCENT', 'snapshot_id' => $preview['snapshot_id'], 'limit' => 1])->assertOk()->assertJsonPath('results.0.price_accepted', true);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && data_get($request->data(), 'pricingSummary.price.value') === '107.00' && $request['availableQuantity'] === 3 && $request['listingDescription'] === 'Keep me');
        $gearbox->refresh();
        $this->assertSame('9999.00', $gearbox->price);
        $this->assertSame(8, $gearbox->quantity);
        $this->assertSame('Untouched', $gearbox->title);
        $this->assertDatabaseHas('marketplace_sync_logs', ['action' => 'jarek_gearboxes_ebay_bulk_price_increase_apply', 'request_id' => 'write-1']);
    }

    public function test_apply_runner_exposes_no_run_all_action(): void
    {
        $html = $this->withoutMiddleware()->get('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-runner')->getContent();
        $this->assertStringContainsString('Start Canary', $html);
        $this->assertStringNotContainsString('>Run All<', $html);
    }

    public function test_bulk_preview_prefers_fetch_cache_not_local_price_and_excludes_parts(): void
    {
        $this->gearbox(['price' => 9000, 'ebay_offer_id' => 'offer-3', 'ebay_inventory_sku' => 'JAREK-3', 'ebay_payload_snapshot' => ['pricingSummary' => ['price' => ['value' => 80, 'currency' => 'EUR']], '_jarek_price_fetch' => ['pricingSummary' => ['price' => ['value' => 100, 'currency' => 'EUR']], 'fetched_at' => '2026-09-09T10:00:00Z']]]);
        Part::query()->create(['name' => 'Ordinary Part', 'price' => 100, 'ebay_price' => 100]);

        $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-preview?percent=7')->assertOk()
            ->assertJsonPath('total_jarek_products', 1)->assertJsonPath('sample_products.0.current_ebay_price', 100)
            ->assertJsonPath('sample_products.0.new_price', 107)->assertJsonPath('sample_products.0.price_source', 'ebay_offer_fetch_cache');
    }

    private function gearbox(array $attributes): JarekGearbox
    {
        return JarekGearbox::query()->create(array_merge(['source_account' => 'jarek', 'allegro_account' => 'jarek', 'allegro_offer_id' => uniqid(), 'title' => 'Jarek product', 'currency' => 'PLN', 'quantity' => 1, 'ebay_status' => 'published'], $attributes));
    }
}

<?php

namespace Tests\Feature;

use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Services\JarekGearboxes\JarekGearboxEbayPriceApplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class JarekGearboxEbayBulkPricePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_runner_exposes_read_only_preview_loader_and_navigation_safe_debug_ui(): void
    {
        $response = $this->withoutMiddleware()->get('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply-runner');

        $response->assertOk()
            ->assertSee('data-preview-url="'.url('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-preview').'?percent=7"', false)
            ->assertSee("method: 'GET'", false)
            ->assertSee("document.addEventListener('DOMContentLoaded', initialize)", false)
            ->assertSee("document.addEventListener('livewire:navigated', initialize)", false)
            ->assertSee("document.addEventListener('filament:navigated', initialize)", false)
            ->assertSee('runtime.listenersBound', false)
            ->assertSee('preview_click_count')
            ->assertSee('last_preview_endpoint')
            ->assertSee('last_http_status')
            ->assertSee('last_response_is_json')
            ->assertSee('last_error_type')
            ->assertSee('last_response_preview')
            ->assertSee('data-resume-url="'.url('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply-runner-resume').'"', false)
            ->assertSee('resume_click_count')
            ->assertSee('last_resume_endpoint')
            ->assertSee('last_resume_http_status')
            ->assertSee('last_resume_response_is_json')
            ->assertSee('last_resume_error_type')
            ->assertSee('last_resume_response_preview')
            ->assertSee("['running', 'paused', 'stopped_on_error']", false)
            ->assertSee('active_timer')
            ->assertSee('next_batch_scheduled_at')
            ->assertSee('Odśwież stronę i kliknij Resume.')
            ->assertSee('APPLY_JAREK_EBAY_PRICES_7_PERCENT_BATCH_RUNNER');
    }

    public function test_resume_uses_existing_run_and_current_offset_for_exactly_one_mocked_batch(): void
    {
        config()->set('marketplace.jarek_ebay_price_apply_enabled', true);
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'api_enabled' => true, 'api_settings' => ['write_connection_enabled' => true]]);
        Cache::forever('jarek:ebay-de:price-apply-runner', $this->runnerState());
        Http::fake();

        $apply = Mockery::mock(JarekGearboxEbayPriceApplyService::class);
        $apply->shouldReceive('apply')->once()->with('snapshot-existing', 5, 320, [], 'run-existing')->andReturn([
            'ok' => true,
            'apply_run_id' => 'run-existing',
            'apply_batch_id' => 'batch-next',
            'results' => [
                ['status' => 'already_updated', 'marketplace_write' => false],
                ['status' => 'success', 'marketplace_write' => false],
            ],
        ]);
        $this->app->instance(JarekGearboxEbayPriceApplyService::class, $apply);

        $this->withoutMiddleware()->postJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply-runner-resume', [
            'snapshot_id' => 'snapshot-existing',
            'confirm' => 'APPLY_JAREK_EBAY_PRICES_7_PERCENT_BATCH_RUNNER',
        ])->assertOk()
            ->assertJsonPath('apply_run_id', 'run-existing')
            ->assertJsonPath('current_offset', 322)
            ->assertJsonPath('processed_count', 322)
            ->assertJsonPath('batch_history.0.offset', 320);

        $state = Cache::get('jarek:ebay-de:price-apply-runner');
        $this->assertSame('run-existing', $state['apply_run_id']);
        $this->assertSame(322, $state['current_offset']);
        Http::assertNothingSent();
    }

    public function test_stopped_invalid_token_resume_refreshes_ebay_de_and_continues_at_saved_offset(): void
    {
        config()->set('marketplace.jarek_ebay_price_apply_enabled', true);
        $account = MarketplaceAccount::query()->create([
            'marketplace' => 'ebay',
            'name' => 'eBay DE',
            'code' => 'ebay_de',
            'api_enabled' => true,
            'api_base_url' => 'https://api.ebay.test',
            'api_settings' => ['write_connection_enabled' => true, 'marketplace_id' => 'EBAY_DE'],
            'api_credentials' => ['access_token' => 'expired', 'client_id' => 'client', 'client_secret' => 'secret', 'refresh_token' => 'refresh', 'scopes' => 'sell.inventory sell.fulfillment'],
        ]);
        Cache::forever('jarek:ebay-de:price-apply-runner', array_replace($this->runnerState(), [
            'status' => 'stopped_on_error',
            'current_offset' => 596,
            'processed_count' => 596,
            'last_error' => ['error' => 'Invalid access token'],
        ]));
        Http::fake(['https://api.ebay.test/identity/v1/oauth2/token' => Http::response([
            'access_token' => 'fresh',
            'expires_in' => 7200,
            'token_type' => 'User Access Token',
            'scope' => 'sell.inventory sell.fulfillment',
        ])]);

        $apply = Mockery::mock(JarekGearboxEbayPriceApplyService::class);
        $apply->shouldReceive('apply')->once()->with('snapshot-existing', 5, 596, [], 'run-existing')->andReturn([
            'ok' => true,
            'apply_run_id' => 'run-existing',
            'apply_batch_id' => 'batch-resumed',
            'results' => array_fill(0, 5, ['status' => 'already_updated', 'marketplace_write' => false]),
        ]);
        $this->app->instance(JarekGearboxEbayPriceApplyService::class, $apply);

        $this->withoutMiddleware()->postJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply-runner-resume', [
            'snapshot_id' => 'snapshot-existing',
            'confirm' => 'APPLY_JAREK_EBAY_PRICES_7_PERCENT_BATCH_RUNNER',
        ])->assertOk()
            ->assertJsonPath('apply_run_id', 'run-existing')
            ->assertJsonPath('refresh_attempted', true)
            ->assertJsonPath('refreshed', true)
            ->assertJsonPath('token_expires_at', '[REDACTED]')
            ->assertJsonPath('account_code', 'ebay_de')
            ->assertJsonPath('marketplace_id', 'EBAY_DE')
            ->assertJsonPath('batch_size', 5)
            ->assertJsonPath('delay_ms', 4000)
            ->assertJsonPath('batch_history.0.offset', 596)
            ->assertJsonPath('next_offset', 601);

        $this->assertSame('fresh', data_get($account->fresh(), 'api_credentials.access_token'));
        Http::assertSentCount(1);
    }

    public function test_stopped_runner_rejects_non_oauth_error_without_refresh_or_apply(): void
    {
        config()->set('marketplace.jarek_ebay_price_apply_enabled', true);
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'api_enabled' => true, 'api_settings' => ['write_connection_enabled' => true]]);
        Cache::forever('jarek:ebay-de:price-apply-runner', array_replace($this->runnerState(), [
            'status' => 'stopped_on_error',
            'last_error' => ['error' => 'remote_price_drift'],
        ]));
        Http::fake();
        $apply = Mockery::mock(JarekGearboxEbayPriceApplyService::class);
        $apply->shouldNotReceive('apply');
        $this->app->instance(JarekGearboxEbayPriceApplyService::class, $apply);

        $this->withoutMiddleware()->postJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply-runner-resume', [
            'snapshot_id' => 'snapshot-existing',
            'confirm' => 'APPLY_JAREK_EBAY_PRICES_7_PERCENT_BATCH_RUNNER',
        ])->assertStatus(409)->assertJsonPath('error', 'runner_cannot_be_resumed');

        Http::assertNothingSent();
    }

    public function test_resume_rejects_missing_confirmation_snapshot_and_mismatched_runner_without_apply(): void
    {
        Cache::forever('jarek:ebay-de:price-apply-runner', $this->runnerState());
        $apply = Mockery::mock(JarekGearboxEbayPriceApplyService::class);
        $apply->shouldNotReceive('apply');
        $this->app->instance(JarekGearboxEbayPriceApplyService::class, $apply);
        $url = '/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply-runner-resume';

        $this->withoutMiddleware()->postJson($url, [])->assertForbidden();
        $this->withoutMiddleware()->postJson($url, ['confirm' => 'APPLY_JAREK_EBAY_PRICES_7_PERCENT_BATCH_RUNNER'])->assertStatus(422);

        config()->set('marketplace.jarek_ebay_price_apply_enabled', true);
        MarketplaceAccount::query()->create(['marketplace' => 'ebay', 'name' => 'eBay DE', 'code' => 'ebay_de', 'api_enabled' => true, 'api_settings' => ['write_connection_enabled' => true]]);
        $this->withoutMiddleware()->postJson($url, ['snapshot_id' => 'different', 'confirm' => 'APPLY_JAREK_EBAY_PRICES_7_PERCENT_BATCH_RUNNER'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'snapshot_id_does_not_match_runner')
            ->assertJsonPath('current_offset', 320);

        $this->assertSame($this->runnerState(), Cache::get('jarek:ebay-de:price-apply-runner'));
    }

    public function test_resume_route_requires_csrf(): void
    {
        $this->postJson('/admin/tools/jarek-gearboxes/ebay-bulk-price-increase-apply-runner-resume', [
            'snapshot_id' => 'snapshot-existing',
            'confirm' => 'APPLY_JAREK_EBAY_PRICES_7_PERCENT_BATCH_RUNNER',
        ])->assertStatus(419);
    }

    public function test_preview_is_read_only_calculates_seven_percent_and_excludes_parts(): void
    {
        Http::fake();
        $eligible = $this->gearbox([
            'price' => 400,
            'ebay_offer_id' => 'offer-1',
            'ebay_listing_id' => 'item-1',
            'ebay_inventory_sku' => 'JAREK-1',
            'ebay_status' => 'published',
            'ebay_payload_snapshot' => ['marketplaceId' => 'EBAY_DE', '_jarek_price_fetch' => ['fetched_at' => now()->toIso8601String(), 'pricingSummary' => ['price' => ['value' => '100.00', 'currency' => 'EUR']]]],
        ]);
        $withoutListing = $this->gearbox(['allegro_offer_id' => '2', 'price' => 200]);
        $zero = $this->gearbox([
            'allegro_offer_id' => '3',
            'price' => 0,
            'ebay_offer_id' => 'offer-3',
            'ebay_inventory_sku' => 'JAREK-3',
            'ebay_status' => 'published',
            'ebay_payload_snapshot' => ['_jarek_price_fetch' => ['fetched_at' => now()->toIso8601String(), 'pricingSummary' => ['price' => ['value' => 0, 'currency' => 'EUR']]]],
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

    private function runnerState(): array
    {
        return [
            'ok' => true,
            'apply_run_id' => 'run-existing',
            'snapshot_id' => 'snapshot-existing',
            'status' => 'running',
            'current_offset' => 320,
            'processed_count' => 320,
            'success_count' => 319,
            'failed_count' => 1,
            'skipped_count' => 0,
            'remaining_count' => 1029,
            'eligible_count' => 1349,
            'batch_size' => 5,
            'delay_ms' => 4000,
            'last_success' => null,
            'last_error' => null,
            'batch_history' => [],
            'started_at' => now()->toIso8601String(),
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\MarketplaceOrdersImportService;
use App\Services\Marketplace\OAuthTokenManager;
use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroUserAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_allegro_api_client_sends_user_agent_on_read_write_and_oauth_refresh_requests(): void
    {
        config(['marketplace.allegro_user_agent' => 'TestApp/1.2 (+https://example.test/api-info)']);
        Http::fake([
            'https://api.allegro.test/sale/product-offers' => Http::response(['id' => 'offer-1'], 201),
            'https://api.allegro.test/sale/product-offers/offer-1' => Http::response(['id' => 'offer-1'], 200),
            'https://api.allegro.test/order/checkout-forms/cf-1/fulfillment' => Http::response(['ok' => true], 200),
            'https://allegro.pl/auth/oauth/token' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 3600], 200),
        ]);
        $account = $this->account(['client_id' => 'cid', 'client_secret' => 'secret', 'refresh_token' => 'refresh']);
        $client = new AllegroApiClient('allegro_main', $account);

        $client->createProductOffer(['name' => 'Offer']);
        $client->productOffer('offer-1');
        $client->updateOrderFulfillmentStatus('cf-1', 'PROCESSING');
        app(OAuthTokenManager::class)->refresh($account);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.allegro.test')
            && $request->hasHeader('User-Agent', 'TestApp/1.2 (+https://example.test/api-info)'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://allegro.pl/auth/oauth/token'
            && $request->hasHeader('User-Agent', 'TestApp/1.2 (+https://example.test/api-info)'));
    }

    public function test_order_import_and_fallback_user_agent_are_exposed_in_diagnostics(): void
    {
        config(['marketplace.allegro_user_agent' => '']);
        Http::fake(['https://api.allegro.test/order/checkout-forms*' => Http::response(['checkoutForms' => []], 200)]);
        $this->account();

        app(MarketplaceOrdersImportService::class)->run(['marketplace' => 'allegro', 'dry_run' => true, 'limit' => 1]);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.allegro.test/order/checkout-forms')
            && $request->hasHeader('User-Agent', AllegroUserAgent::FALLBACK));
        $this->getJson('/tools/check-allegro-api-settings?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('user_agent', AllegroUserAgent::FALLBACK)
            ->assertJsonPath('user_agent_config_key', 'GPS_ALLEGRO_USER_AGENT');
    }

    public function test_public_api_info_page_exists_for_user_agent_url(): void
    {
        $this->get('/api-info')->assertOk()->assertSee('GPswiss/v1.0 (+https://gpswiss.pl/api-info)');
    }

    private function account(array $credentials = []): MarketplaceAccount
    {
        return MarketplaceAccount::query()->create([
            'code' => 'allegro_main',
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.test',
            'api_mode' => 'read_only',
            'api_credentials' => array_merge(['access_token' => 'token'], $credentials),
        ]);
    }
}

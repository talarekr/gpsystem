<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\OAuthTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceOAuthTokenManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_allegro_refresh_persists_rotated_refresh_token(): void
    {
        Http::fake(['https://allegro.pl/auth/oauth/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'token_type' => 'bearer'], 200)]);
        $account = MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['client_id' => 'cid', 'client_secret' => 'secret', 'access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'access_token_expires_at' => now()->subMinute()->toISOString()]]);

        $result = app(OAuthTokenManager::class)->ensureValidToken($account);

        $this->assertTrue($result['ok']);
        $credentials = $account->fresh()->api_credentials;
        $this->assertSame('new-access', $credentials['access_token']);
        $this->assertSame('new-refresh', $credentials['refresh_token']);
        $this->assertNotEmpty($credentials['access_token_expires_at']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'allegro', 'action' => 'oauth_token_refresh', 'status' => 'success']);
    }

    public function test_ebay_refresh_persists_new_access_token_and_expiry(): void
    {
        Http::fake(['https://api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'ebay-new-access', 'expires_in' => 7200, 'token_type' => 'User Access Token'], 200)]);
        $account = MarketplaceAccount::query()->create(['code' => 'ebay_de', 'marketplace' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.com', 'api_credentials' => ['client_id' => 'cid', 'client_secret' => 'secret', 'access_token' => 'old-access', 'refresh_token' => 'refresh', 'expires_at' => now()->subMinute()->toISOString()]]);

        $result = app(OAuthTokenManager::class)->ensureValidToken($account);

        $this->assertTrue($result['ok']);
        $credentials = $account->fresh()->api_credentials;
        $this->assertSame('ebay-new-access', $credentials['access_token']);
        $this->assertNotEmpty($credentials['expires_at']);
        $this->assertSame('success', $credentials['last_refresh_status']);
    }

    public function test_401_triggers_single_refresh_and_one_retry(): void
    {
        Http::fakeSequence()
            ->push([], 401)
            ->push(['access_token' => 'retry-access', 'refresh_token' => 'retry-refresh', 'expires_in' => 3600], 200)
            ->push(['offers' => [['id' => 'offer-1', 'name' => 'Read only offer']]], 200);
        $account = MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_mode' => 'read_only', 'api_credentials' => ['client_id' => 'cid', 'client_secret' => 'secret', 'access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'access_token_expires_at' => now()->addHour()->toISOString()]]);

        $result = (new AllegroApiClient('allegro_main', $account))->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['http_status']);
        Http::assertSentCount(3);
        $this->assertSame('retry-refresh', $account->fresh()->api_credentials['refresh_token']);
    }

    public function test_refresh_failure_is_reported_as_auth_error_not_empty_data(): void
    {
        Http::fake(['https://allegro.pl/auth/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $account = MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['client_id' => 'cid', 'client_secret' => 'secret', 'access_token' => 'old-access', 'refresh_token' => 'bad-refresh', 'access_token_expires_at' => now()->subMinute()->toISOString()]]);

        $result = app(OAuthTokenManager::class)->ensureValidToken($account);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('OAuth token refresh failed', $result['message']);
        $this->assertSame('failure', $account->fresh()->api_credentials['last_refresh_status']);
        $this->assertDatabaseHas('marketplace_sync_logs', ['marketplace' => 'allegro', 'action' => 'oauth_token_refresh', 'status' => 'failure']);
    }
}

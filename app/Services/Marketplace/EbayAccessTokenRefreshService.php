<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Support\Marketplace\EbayOAuthConfig;
use Illuminate\Support\Facades\Http;

class EbayAccessTokenRefreshService
{
    /** @return array{ok: bool, refresh_attempted: bool, refreshed: bool, token_expires_at: string|null, account_code: string, marketplace_id: string, error?: string} */
    public function refreshForResume(MarketplaceAccount $account): array
    {
        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $diagnostics = [
            'ok' => true,
            'refresh_attempted' => false,
            'refreshed' => false,
            // Do not return credential metadata from this write endpoint.
            'token_expires_at' => filled($credentials['expires_at'] ?? $credentials['access_token_expires_at'] ?? null) ? '[REDACTED]' : null,
            'account_code' => (string) $account->code,
            'marketplace_id' => (string) ($settings['marketplace_id'] ?? 'EBAY_DE'),
        ];

        $canRefresh = filled($credentials['client_id'] ?? null)
            && filled($credentials['client_secret'] ?? null)
            && filled($credentials['refresh_token'] ?? null);

        // The stopped runner has already established that its token was invalid.
        // Always force OAuth refresh when the account has refresh credentials.
        if (! $canRefresh) {
            return $diagnostics;
        }

        $diagnostics['refresh_attempted'] = true;
        $response = Http::asForm()
            ->withBasicAuth((string) $credentials['client_id'], (string) $credentials['client_secret'])
            ->acceptJson()
            ->timeout(20)
            ->post(EbayOAuthConfig::tokenUrl((string) $account->api_base_url), [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $credentials['refresh_token'],
                'scope' => (string) ($credentials['scopes'] ?? EbayOAuthConfig::scopeString()),
            ]);
        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload) || blank($payload['access_token'] ?? null)) {
            return array_replace($diagnostics, ['ok' => false, 'error' => 'ebay_oauth_refresh_failed']);
        }

        $updated = array_merge($credentials, [
            'access_token' => (string) $payload['access_token'],
            'expires_at' => EbayOAuthConfig::tokenExpiresAt($payload['expires_in'] ?? null),
            'token_type' => (string) ($payload['token_type'] ?? ($credentials['token_type'] ?? '')),
            'scopes' => $payload['scope'] ?? ($credentials['scopes'] ?? EbayOAuthConfig::scopeString()),
            'refreshed_at' => now()->toISOString(),
        ]);
        if (filled($payload['refresh_token'] ?? null)) {
            $updated['refresh_token'] = (string) $payload['refresh_token'];
        }

        $account->forceFill([
            'api_credentials' => $updated,
            'last_connection_check_at' => now(),
            'last_connection_status' => 'ok',
            'last_connection_message' => 'eBay access token refreshed securely before price runner resume.',
        ])->save();

        $diagnostics['refreshed'] = true;
        $diagnostics['token_expires_at'] = filled($updated['expires_at'] ?? null) ? '[REDACTED]' : null;

        return $diagnostics;
    }
}

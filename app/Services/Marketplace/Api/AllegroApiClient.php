<?php

namespace App\Services\Marketplace\Api;

use App\Support\Marketplace\AllegroOAuthConfig;
use Illuminate\Support\Facades\Http;

class AllegroApiClient extends AbstractMarketplaceApiClient
{
    protected function requiredCredentialKeys(): array { return ['access_token']; }
    protected function optionalCredentialKeys(): array { return ['client_id', 'client_secret', 'refresh_token']; }
    protected function endpointPath(): string { return '/sale/offers'; }


    public function refreshAccessToken(): array
    {
        $credentials = $this->credentials();
        $clientId = (string) ($credentials['client_id'] ?? '');
        $clientSecret = (string) ($credentials['client_secret'] ?? '');
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if (! $this->account || $clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return ['ok' => false, 'status' => 'not_ready', 'message' => 'Allegro refresh token prerequisites are missing.'];
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->acceptJson()
            ->timeout(20)
            ->post(AllegroOAuthConfig::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if (! $response->successful()) {
            $this->storeStatus('failed', 'Allegro access token refresh failed without exposing credentials.');
            return ['ok' => false, 'status' => 'failed', 'http_status' => $response->status()];
        }

        $payload = $response->json();
        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            $this->storeStatus('failed', 'Allegro access token refresh response was missing an access token.');
            return ['ok' => false, 'status' => 'invalid_response', 'http_status' => $response->status()];
        }

        $this->account->forceFill([
            'api_credentials' => array_merge($credentials, [
                'access_token' => (string) $payload['access_token'],
                'refresh_token' => (string) ($payload['refresh_token'] ?? $refreshToken),
                'token_type' => (string) ($payload['token_type'] ?? ($credentials['token_type'] ?? '')),
                'expires_in' => $payload['expires_in'] ?? ($credentials['expires_in'] ?? null),
                'access_token_expires_at' => AllegroOAuthConfig::tokenExpiresAt($payload['expires_in'] ?? null),
                'refreshed_at' => now()->toISOString(),
            ]),
            'last_connection_check_at' => now(),
            'last_connection_status' => 'ok',
            'last_connection_message' => 'Allegro access token refreshed securely.',
        ])->save();

        return ['ok' => true, 'status' => 'ok', 'http_status' => $response->status()];
    }

    protected function requestSample(int $limit): array
    {
        $response = Http::withToken((string) $this->credentials()['access_token'])->accept('application/vnd.allegro.public.v1+json')->timeout(15)->get($this->endpointUsed($limit));
        $json = $response->json();
        $error = in_array($response->status(), [401, 403], true) ? 'Access token expired, unauthorized, forbidden, or missing required scopes.' : null;
        return ['http_status' => $response->status(), 'json' => is_array($json) ? $json : [], 'api_ok' => $response->successful(), 'error' => $error];
    }

    protected function extractOffers(array $payload): array
    {
        $rows = $payload['offers'] ?? [];
        return array_values(array_map(fn ($row) => [
            'external_offer_id' => (string) ($row['id'] ?? ''), 'title' => $row['name'] ?? null,
            'sku' => $row['external']['id'] ?? $row['productSet'][0]['product']['id'] ?? null,
            'price' => $row['sellingMode']['price']['amount'] ?? null, 'quantity' => $row['stock']['available'] ?? null,
            'status' => $row['publication']['status'] ?? null, 'url' => $row['publication']['marketplaces']['base']['url'] ?? null,
        ], array_filter($rows, 'is_array')));
    }
}

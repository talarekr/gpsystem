<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Support\Marketplace\AllegroOAuthConfig;
use App\Support\Marketplace\AllegroUserAgent;
use App\Support\Marketplace\EbayOAuthConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class OAuthTokenManager
{
    public const REFRESH_WINDOW_SECONDS = 300;

    public function ensureValidToken(MarketplaceAccount $account): array
    {
        $credentials = $this->credentials($account);
        $expiresAt = $this->expiresAt($account);
        if (filled($credentials['access_token'] ?? null) && $expiresAt && $expiresAt->timestamp > now()->addSeconds(self::REFRESH_WINDOW_SECONDS)->timestamp) {
            return ['ok' => true, 'refreshed' => false, 'access_token' => (string) $credentials['access_token']];
        }

        return $this->refresh($account);
    }

    public function refresh(MarketplaceAccount $account): array
    {
        return $this->provider($account) === 'allegro' ? $this->refreshAllegro($account) : $this->refreshEbay($account);
    }

    public function tokenHealth(): array
    {
        return MarketplaceAccount::query()
            ->whereIn('marketplace', ['allegro', 'ebay_de', 'ebay_fr', 'ebay'])
            ->orWhereIn('code', ['allegro_main', 'ebay_de', 'ebay_fr'])
            ->orderBy('code')
            ->get()
            ->map(fn (MarketplaceAccount $account): array => $this->health($account))
            ->all();
    }

    public function health(MarketplaceAccount $account): array
    {
        $credentials = $this->credentials($account);
        $expiresAt = $this->expiresAt($account);
        $last = MarketplaceSyncLog::query()->where('marketplace', $account->marketplace)->where('action', 'oauth_token_refresh')->latest('created_at')->first();

        return [
            'provider' => $this->provider($account),
            'channel' => $account->code,
            'has_access_token' => filled($credentials['access_token'] ?? null),
            'has_refresh_token' => filled($credentials['refresh_token'] ?? null),
            'access_token_expires_at' => $expiresAt?->toISOString(),
            'seconds_until_expiry' => $expiresAt ? now()->diffInSeconds($expiresAt, false) : null,
            'can_refresh' => $this->canRefresh($account),
            'last_refresh_at' => $credentials['last_refresh_at'] ?? $credentials['refreshed_at'] ?? $last?->created_at?->toISOString(),
            'last_refresh_status' => $credentials['last_refresh_status'] ?? $last?->status,
            'auth_error' => $credentials['auth_error'] ?? ($last && $last->status !== 'success' ? $last->message : null),
        ];
    }

    private function refreshAllegro(MarketplaceAccount $account): array
    {
        $c = $this->credentials($account);
        if (! $this->has($c, ['client_id', 'client_secret', 'refresh_token'])) return $this->fail($account, null, 'Allegro token refresh prerequisites are missing.');
        $response = AllegroUserAgent::request()->asForm()->withBasicAuth((string) $c['client_id'], (string) $c['client_secret'])->acceptJson()->timeout(20)->post(AllegroOAuthConfig::TOKEN_URL, ['grant_type' => 'refresh_token', 'refresh_token' => (string) $c['refresh_token']]);
        return $this->storeRefreshResponse($account, $response, 'access_token_expires_at', fn ($expiresIn) => AllegroOAuthConfig::tokenExpiresAt($expiresIn), true);
    }

    private function refreshEbay(MarketplaceAccount $account): array
    {
        $c = $this->credentials($account);
        if (! $this->has($c, ['client_id', 'client_secret', 'refresh_token'])) return $this->fail($account, null, 'eBay token refresh prerequisites are missing.');
        $response = Http::asForm()->withBasicAuth((string) $c['client_id'], (string) $c['client_secret'])->acceptJson()->timeout(20)->post(EbayOAuthConfig::tokenUrl((string) $account->api_base_url), ['grant_type' => 'refresh_token', 'refresh_token' => (string) $c['refresh_token'], 'scope' => (string) ($c['scopes'] ?? EbayOAuthConfig::scopeString())]);
        return $this->storeRefreshResponse($account, $response, 'expires_at', fn ($expiresIn) => EbayOAuthConfig::tokenExpiresAt($expiresIn), false);
    }

    private function storeRefreshResponse(MarketplaceAccount $account, Response $response, string $expiresKey, callable $expiresAt, bool $rotateFallback): array
    {
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) || blank($payload['access_token'] ?? null)) return $this->fail($account, $response->status(), 'OAuth token refresh failed without exposing credentials.');
        $c = $this->credentials($account);
        $updated = array_merge($c, ['access_token' => (string) $payload['access_token'], $expiresKey => $expiresAt($payload['expires_in'] ?? null), 'expires_in' => $payload['expires_in'] ?? ($c['expires_in'] ?? null), 'token_type' => (string) ($payload['token_type'] ?? ($c['token_type'] ?? '')), 'last_refresh_at' => now()->toISOString(), 'last_refresh_status' => 'success']);
        unset($updated['auth_error']);
        if (filled($payload['refresh_token'] ?? null)) $updated['refresh_token'] = (string) $payload['refresh_token'];
        elseif ($rotateFallback && filled($c['refresh_token'] ?? null)) $updated['refresh_token'] = (string) $c['refresh_token'];
        if (filled($payload['scope'] ?? null)) $updated['scopes'] = (string) $payload['scope'];
        $account->forceFill(['api_credentials' => $updated, 'last_connection_check_at' => now(), 'last_connection_status' => 'ok', 'last_connection_message' => 'OAuth access token refreshed securely.'])->save();
        $this->log($account, 'success', $response->status(), 'OAuth token refresh succeeded.');
        return ['ok' => true, 'refreshed' => true, 'access_token' => (string) $updated['access_token'], 'http_status' => $response->status()];
    }

    private function fail(MarketplaceAccount $account, ?int $httpStatus, string $message): array
    {
        $c = $this->credentials($account); $c['last_refresh_at'] = now()->toISOString(); $c['last_refresh_status'] = 'failure'; $c['auth_error'] = $message;
        $account->forceFill(['api_credentials' => $c, 'last_connection_check_at' => now(), 'last_connection_status' => 'failed', 'last_connection_message' => $message])->save();
        $this->log($account, 'failure', $httpStatus, $message);
        return ['ok' => false, 'refreshed' => false, 'http_status' => $httpStatus, 'message' => $message];
    }

    private function log(MarketplaceAccount $account, string $status, ?int $httpStatus, string $message): void
    { MarketplaceSyncLog::query()->create(['marketplace' => $account->marketplace, 'action' => 'oauth_token_refresh', 'status' => $status, 'http_status' => $httpStatus, 'message' => $message, 'payload' => ['channel' => $account->code, 'provider' => $this->provider($account), 'secrets_logged' => false], 'created_at' => now()]); }
    private function credentials(MarketplaceAccount $account): array { return is_array($account->api_credentials) ? $account->api_credentials : []; }
    private function provider(MarketplaceAccount $account): string { return str_starts_with((string) $account->code, 'allegro') || $account->marketplace === 'allegro' ? 'allegro' : 'ebay'; }
    private function expiresAt(MarketplaceAccount $account): ?Carbon { $c = $this->credentials($account); $v = $c['access_token_expires_at'] ?? $c['expires_at'] ?? null; return filled($v) ? Carbon::parse((string) $v) : null; }
    private function canRefresh(MarketplaceAccount $account): bool { return $this->has($this->credentials($account), ['client_id', 'client_secret', 'refresh_token']); }
    private function has(array $c, array $keys): bool { foreach ($keys as $key) if (blank($c[$key] ?? null)) return false; return true; }
}

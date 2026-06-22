<?php

namespace App\Support\Marketplace;

use App\Models\MarketplaceAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AllegroOAuthConfig
{
    public const ACCOUNT_CODE = 'allegro_main';
    public const REDIRECT_URI = 'https://gpswiss.pl/admin/allegro/oauth/callback';
    public const AUTHORIZATION_URL = 'https://allegro.pl/auth/oauth/authorize';
    public const TOKEN_URL = 'https://allegro.pl/auth/oauth/token';

    public static function account(): ?MarketplaceAccount
    {
        return MarketplaceAccount::query()->where('code', self::ACCOUNT_CODE)->first();
    }

    public static function readiness(?MarketplaceAccount $account = null): array
    {
        $account ??= self::account();
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $blockers = [];
        $warnings = [];

        if (! $account) {
            $blockers[] = 'Marketplace account allegro_main was not found.';
        } else {
            if (! $account->api_enabled) $blockers[] = 'API is not enabled.';
            if (blank($account->api_base_url)) $blockers[] = 'API base URL is missing.';
            if (blank($credentials['client_id'] ?? null)) $blockers[] = 'client_id is missing.';
            if (blank($credentials['client_secret'] ?? null)) $blockers[] = 'client_secret is missing.';
            if (blank($credentials['access_token'] ?? null)) $blockers[] = 'access_token is missing.';
            if (blank($credentials['refresh_token'] ?? null)) $blockers[] = 'refresh_token is missing.';
        }

        return [
            'account_exists' => $account !== null,
            'api_enabled' => (bool) ($account?->api_enabled ?? false),
            'api_base_url' => $account?->api_base_url,
            'client_id_configured' => filled($credentials['client_id'] ?? null),
            'client_secret_configured' => filled($credentials['client_secret'] ?? null),
            'access_token_configured' => filled($credentials['access_token'] ?? null),
            'refresh_token_configured' => filled($credentials['refresh_token'] ?? null),
            'credentials_configured' => filled($credentials['client_id'] ?? null)
                && filled($credentials['client_secret'] ?? null)
                && filled($credentials['access_token'] ?? null)
                && filled($credentials['refresh_token'] ?? null),
            'redirect_uri' => self::REDIRECT_URI,
            'authorization_url_host' => parse_url(self::AUTHORIZATION_URL, PHP_URL_HOST),
            'token_url_host' => parse_url(self::TOKEN_URL, PHP_URL_HOST),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    public static function tokenExpiresAt(int|string|null $expiresIn): ?string
    {
        if (! is_numeric($expiresIn)) return null;

        return Carbon::now()->addSeconds((int) $expiresIn)->toISOString();
    }

    public static function state(): string
    {
        return Str::random(64);
    }
}

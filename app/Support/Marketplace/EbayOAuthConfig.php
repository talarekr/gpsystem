<?php

namespace App\Support\Marketplace;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class EbayOAuthConfig
{
    public const AUTHORIZATION_URL = 'https://auth.ebay.com/oauth2/authorize';
    public const SCOPES = [
        'https://api.ebay.com/oauth/api_scope',
        'https://api.ebay.com/oauth/api_scope/sell.account',
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
        'https://api.ebay.com/oauth/api_scope/sell.marketing',
    ];

    public static function tokenUrl(string $apiBaseUrl): string
    {
        return rtrim($apiBaseUrl ?: 'https://api.ebay.com', '/').'/identity/v1/oauth2/token';
    }

    public static function state(string $channel): string
    {
        return Crypt::encryptString(json_encode([
            'channel' => $channel,
            'nonce' => Str::random(48),
            'issued_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public static function decodeState(string $state): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $channel = $payload['channel'] ?? null;
        if (! in_array($channel, ['ebay_de', 'ebay_fr'], true)) {
            return null;
        }

        return $payload;
    }

    public static function tokenExpiresAt(mixed $expiresIn): ?string
    {
        return is_numeric($expiresIn) ? now()->addSeconds(max(0, ((int) $expiresIn) - 60))->toISOString() : null;
    }

    public static function scopeString(): string
    {
        return implode(' ', self::SCOPES);
    }
}

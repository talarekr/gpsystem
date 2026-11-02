<?php

namespace App\Services\Marketplace\ShippingRateAudit;

use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Deliberately independent of API clients with OAuth retry or persistence. */
class AllegroShippingReadClient
{
    public function get(?object $account, string $path): array
    {
        $safe = ['ok' => false, 'http_status' => null, 'request_attempted' => false,
            'account_exists' => $account !== null, 'api_enabled' => (bool) ($account->api_enabled ?? false),
            'account_enabled' => in_array($account->status ?? null, ['active', 'enabled'], true),
            'credentials_present' => filled($account->api_credentials ?? null), 'credentials_readable' => false,
            'has_access_token' => null, 'has_refresh_token' => null, 'token_expired' => null,
            'access_token_expires_at' => null, 'expiry_metadata_invalid' => false,
            'requires_refresh' => null, 'refresh_prerequisites_present' => false,
            'oauth_refresh' => false, 'refresh_blocked_reason' => null];
        if (! $account) {
            return $safe + ['reason' => 'account_missing'];
        }
        if (! $safe['credentials_present']) {
            return $safe + ['reason' => 'credentials_missing'];
        }
        try {
            $credentials = json_decode(Crypt::decryptString($account->api_credentials), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($credentials)) {
                return $safe + ['reason' => 'credentials_unreadable'];
            }
        } catch (Throwable) {
            return $safe + ['reason' => 'credentials_unreadable'];
        }
        $safe['credentials_readable'] = true;
        $safe['has_access_token'] = is_string($credentials['access_token'] ?? null) && trim($credentials['access_token']) !== '';
        $safe['has_refresh_token'] = is_string($credentials['refresh_token'] ?? null) && trim($credentials['refresh_token']) !== '';
        $safe['refresh_prerequisites_present'] = $safe['has_refresh_token'] && filled($credentials['client_id'] ?? null) && filled($credentials['client_secret'] ?? null);
        $expiry = $credentials['access_token_expires_at'] ?? $credentials['expires_at'] ?? null;
        if (filled($expiry)) {
            try {
                if (! is_string($expiry)) {
                    throw new \UnexpectedValueException;
                }
                $at = Carbon::parse($expiry);
                $safe['access_token_expires_at'] = $at->toIso8601String();
                $safe['token_expired'] = $at->lessThanOrEqualTo(now());
            } catch (Throwable) {
                $safe['expiry_metadata_invalid'] = true;
            }
        }
        if (! $safe['api_enabled'] || ! $safe['account_enabled']) {
            return $safe + ['reason' => 'account_disabled'];
        }
        if (! $safe['has_access_token']) {
            return $safe + ['reason' => 'access_token_missing'];
        }
        if ($safe['token_expired'] === true) {
            $safe['requires_refresh'] = true;
            $safe['refresh_blocked_reason'] = 'oauth_refresh_blocked_by_zero_write';

            return $safe + ['reason' => 'oauth_refresh_required', 'cause' => 'access_token_expired'];
        }
        $base = rtrim((string) ($account->api_base_url ?? ''), '/');
        if (! in_array($base, ['https://api.allegro.pl', 'https://api.allegro.pl.allegrosandbox.pl'], true)
            || ! preg_match('~^/(?:me|sale/shipping-rates|sale/product-offers/[^/?#]+)$~D', $path)) {
            return $safe + ['reason' => 'endpoint_error'];
        }
        $safe['request_attempted'] = true;
        try {
            $response = AllegroUserAgent::request()->withToken($credentials['access_token'])
                ->accept('application/vnd.allegro.public.v1+json')
                ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(15)->get($base.$path);
            $status = $safe['http_status'] = $response->status();
            if (! $response->successful()) {
                if ($status === 401) {
                    $safe['requires_refresh'] = true;
                    $safe['refresh_blocked_reason'] = 'oauth_refresh_blocked_by_zero_write';
                }
                $reason = match (true) {
                    $status === 401 => 'unauthorized_401', $status === 403 => 'forbidden_403',
                    $status === 429 => 'provider_429', $status >= 500 => 'provider_5xx',
                    $status >= 300 && $status < 500 => 'endpoint_error', default => 'unknown',
                };

                return $safe + ['reason' => $reason];
            }
            $body = $response->json();
            if (! is_array($body)) {
                return $safe + ['reason' => 'invalid_response'];
            }
            $safe['ok'] = true;
            $safe['requires_refresh'] = false;

            return $safe + ['reason' => null, 'body' => $body];
        } catch (ConnectionException) {
            return $safe + ['reason' => 'network_error'];
        } catch (Throwable) {
            // No response bodies, credentials or exception messages in the report.
            return $safe + ['reason' => 'unknown'];
        }
    }
}

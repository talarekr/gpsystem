<?php

namespace App\Services\Marketplace\ShippingRateAudit;

use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Deliberately independent of API clients with OAuth retry or persistence. */
class AllegroShippingReadClient
{
    public function get(object $account, string $path): array
    {
        if (! $account->api_enabled || ! in_array($account->status, ['active', 'enabled'], true)) {
            return ['ok' => false, 'reason' => 'account_unavailable'];
        }
        try {
            $credentials = json_decode(Crypt::decryptString($account->api_credentials ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $token = $credentials['access_token'] ?? '';
            if (! is_string($token) || $token === '') {
                return ['ok' => false, 'reason' => 'account_unavailable'];
            }
            // Expired/unauthorized tokens are never refreshed by this diagnostic.
            $base = rtrim((string) $account->api_base_url, '/');
            if (! in_array($base, ['https://api.allegro.pl', 'https://api.allegro.pl.allegrosandbox.pl'], true)) {
                return ['ok' => false, 'reason' => 'account_unavailable'];
            }
            $response = AllegroUserAgent::request()->withToken($token)
                ->accept('application/vnd.allegro.public.v1+json')
                ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(15)
                ->get($base.$path);
            $body = $response->json();
            if (! $response->successful() || ! is_array($body)) {
                return ['ok' => false, 'reason' => 'provider_read_failed', 'http_status' => $response->status()];
            }

            return ['ok' => true, 'body' => $body];
        } catch (Throwable) {
            // No response bodies, credentials or exception messages in the report.
            return ['ok' => false, 'reason' => 'provider_read_failed'];
        }
    }
}

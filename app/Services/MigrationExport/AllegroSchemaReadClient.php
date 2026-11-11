<?php

namespace App\Services\MigrationExport;

use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Two fixed GET endpoints only. No OAuth manager, retries, logger, model save or provider write methods. */
final class AllegroSchemaReadClient
{
    public const ENDPOINTS = ['parameters', 'product-parameters'];

    public function get(string $category, string $endpoint): array
    {
        if (! preg_match('/\A[0-9]{1,30}\z/D', $category) || ! in_array($endpoint, self::ENDPOINTS, true)) {
            throw new MigrationExportException('schema_endpoint_not_allowed');
        }
        $account = DB::table('marketplace_accounts')->where('code', 'allegro_main')->first();
        if (! $account || ! $account->api_enabled || ! in_array($account->status, ['active', 'enabled'], true)) {
            return ['ok' => false, 'reason_code' => 'account_missing_or_disabled', 'blocked' => true];
        }
        try {
            $credentials = json_decode(Crypt::decryptString($account->api_credentials), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['ok' => false, 'reason_code' => 'credentials_unreadable', 'blocked' => true];
        }
        if (! is_string($credentials['access_token'] ?? null) || $credentials['access_token'] === '') {
            return ['ok' => false, 'reason_code' => 'access_token_missing', 'blocked' => true];
        }
        try {
            $expiry = $credentials['access_token_expires_at'] ?? $credentials['expires_at'] ?? null;
            if ($expiry !== null && Carbon::parse($expiry)->lessThanOrEqualTo(now())) {
                return ['ok' => false, 'reason_code' => 'oauth_refresh_required', 'blocked' => true];
            }
        } catch (\Throwable) {
            return ['ok' => false, 'reason_code' => 'token_expiry_invalid', 'blocked' => true];
        }
        $base = rtrim((string) ($account->api_base_url ?: 'https://api.allegro.pl'), '/');
        if (! in_array($base, ['https://api.allegro.pl', 'https://api.allegro.pl.allegrosandbox.pl'], true)) {
            return ['ok' => false, 'reason_code' => 'api_environment_not_allowed', 'blocked' => true];
        }
        $path = '/sale/categories/'.$category.'/'.$endpoint;
        try {
            $response = AllegroUserAgent::request()->withToken($credentials['access_token'])->accept('application/vnd.allegro.public.v1+json')->withHeaders(['Accept-Language' => 'pl-PL'])
                ->withOptions(['allow_redirects' => false])->connectTimeout(2)->timeout(6)->get($base.$path);
            $status = $response->status();
            if ($status !== 200) {
                $retry = $response->header('Retry-After');
                $seconds = is_string($retry) && ctype_digit($retry) ? (int) $retry : max(0, (int) ((strtotime((string) $retry) ?: now()->timestamp) - now()->timestamp));

                return ['ok' => false, 'http_status' => $status, 'reason_code' => match (true) {
                    $status === 401 => 'oauth_refresh_required', $status === 403 => 'forbidden_403', $status === 404 => 'category_or_endpoint_404', $status === 429 => 'provider_429', $status >= 500 => 'provider_5xx', default => 'provider_http_error',
                }, 'blocked' => in_array($status, [401, 403], true), 'retryable' => $status === 429 || $status >= 500, 'retry_after_seconds' => $seconds];
            }
            $body = $response->body();
            if (strlen($body) > 16 * 1024 * 1024) {
                return ['ok' => false, 'http_status' => 200, 'reason_code' => 'schema_response_budget_exceeded'];
            }
            $raw = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($raw) || ! is_array($raw['parameters'] ?? null) || ! array_is_list($raw['parameters'])) {
                throw new \UnexpectedValueException;
            }
            foreach ($raw['parameters'] as $parameter) {
                if (! is_array($parameter) || ! is_scalar($parameter['id'] ?? null) || (isset($parameter['dictionary']) && ! is_array($parameter['dictionary'])) || (isset($parameter['options']) && ! is_array($parameter['options'])) || (isset($parameter['restrictions']) && ! is_array($parameter['restrictions']))) {
                    throw new \UnexpectedValueException;
                }
            }

            return ['ok' => true, 'http_status' => 200, 'reason_code' => 'schema_fetched', 'category_id' => $category, 'endpoint' => 'GET '.$path,
                'source' => $base.$path, 'fetched_at' => now()->toIso8601String(), 'raw_response' => $raw, 'raw_response_text' => $body, 'sha256' => hash('sha256', $body)];
        } catch (ConnectionException) {
            return ['ok' => false, 'reason_code' => 'network_error', 'retryable' => true];
        } catch (\Throwable) {
            return ['ok' => false, 'http_status' => 200, 'reason_code' => 'invalid_schema_response', 'retryable' => true];
        }
    }
}

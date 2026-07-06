<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EbayApiErrorLogger
{
    private const SECRET_KEYS = ['authorization','access_token','refresh_token','client_secret','client_id_secret','token','ebayauthtoken','X-EBAY-API-IAF-TOKEN'];

    public function logHttpError(Response $response, string $method, string $url, array $context = [], array $payload = [], array $headers = []): void
    {
        if ($response->status() < 400) return;

        $account = $context['account'] ?? null;
        $body = $this->safeResponseBody($response);
        $row = [
            'timestamp' => now()->toISOString(),
            'part_id' => $context['part_id'] ?? null,
            'marketplace' => $context['channel'] ?? $account?->code ?? $account?->marketplace ?? 'ebay',
            'channel' => $context['channel'] ?? $account?->code ?? null,
            'stage' => $context['stage'] ?? $context['action'] ?? 'ebay_api',
            'http_method' => strtoupper($method),
            'endpoint_path' => $this->safePath($url),
            'http_status' => $response->status(),
            'request_id' => $this->requestId($response),
            'response_headers' => $this->diagnosticHeaders($response),
            'response_body' => $body,
            'ebay_errors' => $body['errors'] ?? [],
            'token_metadata' => $this->tokenMetadata($account),
            'request_headers_sanitized' => $this->sanitize($headers),
            'request_payload_sanitized' => $this->sanitize($payload),
        ];

        Log::warning('eBay API HTTP error', $row);

        if (! Schema::hasTable('marketplace_sync_logs')) return;

        MarketplaceSyncLog::query()->create([
            'marketplace' => (string) ($row['marketplace'] ?: 'ebay'),
            'part_id' => $row['part_id'],
            'action' => 'ebay_api_error:'.$row['stage'],
            'status' => 'error',
            'http_status' => $row['http_status'],
            'request_id' => $row['request_id'],
            'message' => $this->message($row),
            'payload' => $row,
            'created_at' => now(),
        ]);
    }

    private function safeResponseBody(Response $response): array|string|null
    {
        $json = $response->json();
        if (is_array($json)) return $this->sanitize($json);
        $body = trim($response->body());
        return $body === '' ? null : Str::limit($this->maskSecrets($body), 20000, '…');
    }

    private function safePath(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;
        $query = $parts['query'] ?? '';
        parse_str($query, $params);
        $params = $this->sanitize($params);
        return $path.($params ? '?'.http_build_query($params) : '');
    }

    private function diagnosticHeaders(Response $response): array
    {
        $keep = ['x-ebay-c-request-id','x-ebay-request-id','rlogid','x-ebay-correlation-id','content-type','retry-after','x-ebay-api-call-name','x-ebay-api-siteid'];
        $headers = [];
        foreach ($response->headers() as $name => $value) {
            if (in_array(strtolower($name), $keep, true)) $headers[$name] = $value;
        }
        return $headers;
    }

    private function requestId(Response $response): ?string
    {
        foreach (['x-ebay-c-request-id','x-ebay-request-id','x-ebay-correlation-id','rlogid'] as $header) {
            if ($response->header($header)) return (string) $response->header($header);
        }
        return null;
    }

    private function tokenMetadata(?MarketplaceAccount $account): array
    {
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        return array_filter([
            'token_type' => $credentials['token_type'] ?? (filled($credentials['refresh_token'] ?? null) ? 'user_token' : (filled($credentials['access_token'] ?? null) ? 'access_token' : null)),
            'marketplace_id' => $settings['marketplace_id'] ?? null,
            'account_id' => $account?->id,
            'account_code' => $account?->code,
            'user_id' => $credentials['user_id'] ?? $credentials['ebay_user_id'] ?? null,
            'scopes' => $credentials['scopes'] ?? $credentials['scope'] ?? null,
            'expires_at' => $credentials['expires_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function message(array $row): string
    {
        $first = $row['ebay_errors'][0] ?? [];
        $msg = is_array($first) ? ($first['longMessage'] ?? $first['message'] ?? null) : null;
        return trim('eBay API '.$row['http_status'].' '.$row['http_method'].' '.$row['endpoint_path'].($msg ? ': '.$msg : ''));
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $keyString = strtolower((string) $key);
                $out[$key] = collect(self::SECRET_KEYS)->contains(fn ($secret) => str_contains($keyString, strtolower($secret))) ? '[MASKED]' : $this->sanitize($item);
            }
            return $out;
        }
        return is_string($value) ? $this->maskSecrets($value) : $value;
    }

    private function maskSecrets(string $value): string
    {
        return preg_replace('/(access_token|refresh_token|client_secret|Authorization|eBayAuthToken)(["\'\s:=]+)([^"\'\s<>&]+)/i', '$1$2[MASKED]', $value) ?? $value;
    }
}

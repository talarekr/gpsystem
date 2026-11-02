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
    private ?float $deadline = null;

    private ?array $tick = null;

    private array $lastMetrics = [];

    public function lastTickMetrics(): array
    {
        return $this->lastMetrics;
    }

    public function tick(array $state, \Closure $work): array
    {
        $this->tick = ['cache' => $state['cache'] ?? [], 'deferred' => false,
            'metrics' => ['provider_requests' => 0, 'http_status_breakdown' => [], 'retry_count' => 0, 'transport_errors' => []]];
        $previous = $this->deadline;
        $this->deadline = microtime(true) + 3.5;
        try {
            $report = $work();

            return $report + ['tick_state' => ['cache' => $this->tick['cache']], 'tick_deferred' => $this->tick['deferred'], 'tick_metrics' => $this->tick['metrics']];
        } finally {
            $this->lastMetrics = $this->tick['metrics'];
            $this->tick = null;
            $this->deadline = $previous;
        }
    }

    private function tickGet(?object $account, string $path): array
    {
        $key = ($account->id ?? 'none').'|'.$path;
        $entry = $this->tick['cache'][$key] ?? null;
        if ($entry && ! empty($entry['terminal'])) {
            return array_replace($entry['result'], ['request_attempted' => false, 'cache_hit' => true, 'observed_at' => $entry['read_at'] ?? null]);
        }
        if ($entry && ($entry['due'] ?? '') > now()->toDateTimeString()) {
            $this->tick['deferred'] = true;

            return $entry['result'] + ['deferred' => true];
        }
        if (($this->tick['blocked_until'] ?? '') > now()->toDateTimeString() || $this->tick['metrics']['provider_requests'] >= 2 || $this->deadline - microtime(true) < 0.5) {
            $this->tick['deferred'] = true;

            return ['ok' => false, 'reason' => 'tick_deferred', 'deferred' => true];
        }
        $result = $this->once($account, $path);
        $attempt = ($entry['attempts'] ?? 0) + 1;
        $result['attempts'] = $attempt;
        if ($result['request_attempted'] ?? false) {
            $this->tick['metrics']['provider_requests']++;
            $status = (string) ($result['http_status'] ?? 'network');
            $this->tick['metrics']['http_status_breakdown'][$status] = ($this->tick['metrics']['http_status_breakdown'][$status] ?? 0) + 1;
            $this->tick['metrics']['retry_count'] += (int) ($attempt > 1);
            if (isset($result['transport_reason'])) {
                $this->tick['metrics']['transport_errors'][] = $result['transport_reason'];
            }
        }
        $retry = ($result['reason'] ?? null) === 'network_error' || in_array($result['http_status'] ?? null, [429, 500, 502, 503, 504], true);
        $deferred = $retry && $attempt < 3;
        $due = $deferred ? now()->addSeconds(max(2 ** ($attempt - 1), $result['retry_after_seconds'] ?? 0))->toDateTimeString() : null;
        if ($deferred) {
            $this->tick['blocked_until'] = $due;
            $this->tick['deferred'] = true;
            $result['deferred'] = true;
            $result['retry_not_before'] = $due;
        }
        // The checkpoint never stores full offer bodies, buyer data, tokens or raw provider errors.
        $safe = array_intersect_key($result, array_flip(['ok', 'reason', 'http_status', 'request_attempted', 'attempts', 'retry_not_before', 'retry_after_seconds', 'transport_reason', 'deferred']));
        if (isset($result['body'])) {
            if ($path === '/sale/shipping-rates') {
                $safe['body'] = ['shippingRates' => array_map(static fn ($r) => is_array($r) ? array_intersect_key($r, ['id' => true, 'name' => true]) : null, is_array($result['body']['shippingRates'] ?? null) ? $result['body']['shippingRates'] : [])];
                if (! isset($result['body']['shippingRates'])) {
                    $safe['body'] = [];
                }
            } else {
                $safe['body'] = ['id' => $result['body']['id'] ?? null, 'publication' => ['status' => $result['body']['publication']['status'] ?? null], 'archived' => $result['body']['archived'] ?? false, 'delivery' => ['shippingRates' => ['id' => $result['body']['delivery']['shippingRates']['id'] ?? null]]];
            }
        }
        $this->tick['cache'][$key] = ['terminal' => ! $deferred, 'attempts' => $attempt, 'due' => $due, 'read_at' => now()->timestamp, 'result' => $safe];

        return $safe;
    }

    public function withinBudget(int $seconds, \Closure $work): mixed
    {
        $previous = $this->deadline;
        $this->deadline = microtime(true) + $seconds;
        try {
            return $work();
        } finally {
            $this->deadline = $previous;
        }
    }

    public function get(?object $account, string $path): array
    {
        if ($this->tick !== null) {
            return $this->tickGet($account, $path);
        }
        $attempt = 0;
        do {
            if ($this->deadline !== null && $this->deadline - microtime(true) < 1) {
                return ['ok' => false, 'reason' => 'network_error', 'cause' => 'batch_read_budget_exhausted', 'http_status' => null, 'request_attempted' => false, 'attempts' => $attempt];
            }
            $result = $this->once($account, $path);
            $result['attempts'] = ++$attempt;
            $retry = str_starts_with($path, '/sale/product-offers/')
                && (($result['reason'] ?? null) === 'network_error' || in_array($result['http_status'] ?? null, [429, 500, 502, 503, 504], true));
            if (! $retry || $attempt >= 3) {
                return $result;
            }
            $seconds = $result['retry_after_seconds'] ?? 0;
            // Long provider deadlines are persisted by the runner, never slept through a web timeout.
            if ($seconds > 2) {
                return $result;
            }
            $milliseconds = max(250 * (2 ** ($attempt - 1)), $seconds * 1000);
            if ($this->deadline !== null && $this->deadline - microtime(true) < 1 + $milliseconds / 1000) {
                return $result;
            }
            $this->wait($milliseconds);
        } while (true);
    }

    protected function wait(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    private function once(?object $account, string $path): array
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
            $timeout = $this->tick !== null ? max(0.1, min(3, $this->deadline - microtime(true) - 0.05)) : ($this->deadline === null ? 8 : max(1, min(8, $this->deadline - microtime(true))));
            $response = AllegroUserAgent::request()->withToken($credentials['access_token'])
                ->accept('application/vnd.allegro.public.v1+json')
                ->withOptions(['allow_redirects' => false])->connectTimeout(min(3, $timeout))->timeout($timeout)->get($base.$path);
            $status = $safe['http_status'] = $response->status();
            if ($status === 429) {
                $header = $response->header('Retry-After');
                $seconds = is_string($header) && ctype_digit($header) ? (int) $header : max(0, (int) ((strtotime((string) $header) ?: now()->timestamp) - now()->timestamp));
                $safe['retry_after_seconds'] = $seconds;
                $safe['retry_not_before'] = now()->addSeconds($seconds)->toDateTimeString();
            }
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
        } catch (ConnectionException $exception) {
            $previous = $exception->getPrevious();
            $context = $previous && method_exists($previous, 'getHandlerContext') ? $previous->getHandlerContext() : [];
            $timeout = ($context['errno'] ?? null) === 28 || preg_match('/(?:cURL error 28|timed out|timeout)/i', $exception->getMessage());

            return $safe + ['reason' => 'network_error', 'transport_reason' => $timeout ? 'timeout' : 'connection_error'];
        } catch (Throwable) {
            // No response bodies, credentials or exception messages in the report.
            return $safe + ['reason' => 'unknown'];
        }
    }
}

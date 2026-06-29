<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Marketplace\ApiIntegrationLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FetchOvokoPartStatusDictionaryController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const ENDPOINT_PATH = '/get/part_status';

    public function __construct(private readonly ApiIntegrationLogger $logger) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $account = Schema::hasTable('marketplace_accounts')
            ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first()
            : null;
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $configuredDefault = $this->configuredDefaultPartStatus($account);

        $baseResponse = [
            'ok' => false,
            'dry_run' => true,
            'read_only' => true,
            'ovoko_write' => false,
            'local_update' => false,
            'account_code' => 'ovoko_main',
            'api_enabled' => (bool) ($account?->api_enabled ?? false),
            'api_base_url' => $account?->api_base_url,
            'api_mode' => $account?->api_mode,
            'endpoint_path' => self::ENDPOINT_PATH,
            'endpoint_used' => $this->endpointUsed((string) ($account?->api_base_url ?? '')),
            'request_method' => 'POST',
            'request_format' => 'form-data',
            'will_make_marketplace_write' => false,
            'will_import_part' => false,
            'will_update_part' => false,
            'will_sync_stock' => false,
            'will_import_orders' => false,
            'configured_default_part_status' => $configuredDefault,
            'statuses' => [],
            'raw_top_level_keys' => [],
            'list_items_safe_shape' => [],
            'http_status' => null,
            'api_status_code' => null,
            'api_status_message' => null,
        ];

        if ($account === null) {
            return response()->json($baseResponse + ['api_status_message' => 'Marketplace account ovoko_main was not found.'], 404);
        }

        $validationMessage = $this->validateConfiguration($account, $credentials);
        if ($validationMessage !== null) {
            $this->logDictionaryFetch('not_ready', $validationMessage, $baseResponse);
            return response()->json(array_merge($baseResponse, ['api_status_message' => $validationMessage]), 422);
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post($baseResponse['endpoint_used'], [
                'username' => (string) $credentials['username'],
                'password' => (string) $credentials['password'],
                'user_token' => (string) $credentials['user_token'],
            ]);

            $payload = is_array($response->json()) ? $response->json() : [];
            $statuses = $this->extractStatuses($payload);
            $apiStatusCode = $payload['status_code'] ?? null;
            $apiStatusMessage = $payload['msg'] ?? ($payload['message'] ?? null);
            $ok = $response->successful() && ($apiStatusCode === null || $apiStatusCode === 'R200') && $statuses !== [];
            $message = $ok
                ? 'Fetched Ovoko/RRR part status dictionary (read-only).'
                : 'Ovoko/RRR part status dictionary fetch did not return a usable status list.';

            $result = array_merge($baseResponse, [
                'ok' => true,
                'dictionary_fetch_ok' => $ok,
                'http_status' => $response->status(),
                'api_status_code' => $apiStatusCode,
                'api_status_message' => $apiStatusMessage,
                'statuses' => $statuses,
                'status_count' => count($statuses),
                'raw_top_level_keys' => array_values(array_slice(array_keys($payload), 0, 30)),
                'list_items_safe_shape' => $this->safeListShape($payload),
            ]);

            $this->logDictionaryFetch($ok ? 'success' : 'warning', $message, $result);

            return response()->json($result);
        } catch (ConnectionException) {
            $message = 'Ovoko/RRR part status dictionary request timed out or failed.';
            $this->logDictionaryFetch('error', $message, $baseResponse);
            return response()->json(array_merge($baseResponse, ['api_status_message' => $message]), 502);
        } catch (Throwable) {
            $message = 'Ovoko/RRR part status dictionary request failed unexpectedly.';
            $this->logDictionaryFetch('error', $message, $baseResponse);
            return response()->json(array_merge($baseResponse, ['api_status_message' => $message]), 502);
        }
    }

    private function validateConfiguration(MarketplaceAccount $account, array $credentials): ?string
    {
        if (! $account->api_enabled) return 'Ovoko API is not enabled for ovoko_main.';
        if (blank($account->api_base_url)) return 'Ovoko API base URL is missing.';
        foreach (['username', 'password', 'user_token'] as $key) {
            if (blank($credentials[$key] ?? null)) return 'Ovoko API credentials are not fully configured.';
        }
        return null;
    }

    private function endpointUsed(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').self::ENDPOINT_PATH;
    }

    private function configuredDefaultPartStatus(?MarketplaceAccount $account): mixed
    {
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        return $settings['default_part_status'] ?? $settings['ovoko_default_part_status'] ?? null;
    }

    private function extractStatuses(array $payload): array
    {
        $items = $this->statusItems($payload);
        if (! is_array($items)) return [];

        $statuses = [];
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $id = $item['id'] ?? $item['status_id'] ?? $item['status'] ?? $item['code'] ?? $item['value'] ?? $key;
                $label = $this->extractLabel($item);
                $statuses[] = ['id' => (string) $id, 'label' => filled($label) ? (string) $label : null];
                continue;
            }

            $statuses[] = [
                'id' => filled($item) ? (string) $item : (string) $key,
                'label' => null,
                'raw_scalar' => is_scalar($item) || $item === null ? $item : gettype($item),
            ];
        }

        return array_values(array_filter($statuses, fn (array $status): bool => $status['id'] !== ''));
    }

    private function statusItems(array $payload): mixed
    {
        return $payload['list'] ?? $payload['data'] ?? $payload['statuses'] ?? $payload['part_status'] ?? [];
    }

    private function extractLabel(array $item): mixed
    {
        foreach (['name', 'label', 'title', 'value', 'text', 'pl', 'en', 'lt', 'ru'] as $key) {
            if (filled($item[$key] ?? null) && is_scalar($item[$key])) return $item[$key];
        }

        foreach (['names', 'translations'] as $groupKey) {
            $group = $item[$groupKey] ?? null;
            if (! is_array($group)) continue;
            foreach (['pl', 'en', 'lt', 'ru', 'name', 'label', 'title', 'value', 'text'] as $key) {
                if (filled($group[$key] ?? null) && is_scalar($group[$key])) return $group[$key];
            }
            foreach ($group as $value) {
                if (filled($value) && is_scalar($value)) return $value;
            }
        }

        return null;
    }

    private function safeListShape(array $payload): array
    {
        $items = $this->statusItems($payload);
        if (! is_array($items)) {
            return ['list_type' => gettype($items), 'items' => []];
        }

        $shape = [];
        foreach (array_slice($items, 0, 25, true) as $key => $item) {
            if (! is_array($item)) {
                $shape[] = [
                    'list_key' => (string) $key,
                    'item_type' => gettype($item),
                    'raw_scalar' => is_scalar($item) || $item === null ? $item : null,
                ];
                continue;
            }

            $safe = ['list_key' => (string) $key, 'item_type' => 'array'];
            foreach ($item as $itemKey => $value) {
                if ($this->isSensitiveKey((string) $itemKey)) continue;
                if (is_scalar($value) || $value === null) {
                    $safe[(string) $itemKey] = $value;
                    continue;
                }
                if (in_array((string) $itemKey, ['names', 'translations'], true) && is_array($value)) {
                    $safe[(string) $itemKey] = $this->safeScalarArray($value);
                    continue;
                }
                $safe[(string) $itemKey] = ['type' => gettype($value), 'keys' => is_array($value) ? array_values(array_slice(array_map('strval', array_keys($value)), 0, 20)) : []];
            }
            $safe['detected_label'] = $this->extractLabel($item);
            $shape[] = $safe;
        }

        return $shape;
    }

    private function safeScalarArray(array $values): array
    {
        $safe = [];
        foreach (array_slice($values, 0, 20, true) as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) continue;
            $safe[(string) $key] = is_scalar($value) || $value === null ? $value : ['type' => gettype($value)];
        }
        return $safe;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match('/password|token|secret|credential|authorization|auth|api[_-]?key/i', $key) === 1;
    }

    private function logDictionaryFetch(string $status, string $message, array $result): void
    {
        $this->logger->record([
            'marketplace' => 'ovoko',
            'action' => 'fetch_part_status_dictionary',
            'status' => $status,
            'http_status' => $result['http_status'] ?? null,
            'message' => $message,
            'request' => [
                'account_code' => 'ovoko_main',
                'method' => 'POST',
                'endpoint_path' => self::ENDPOINT_PATH,
                'read_only' => true,
                'credentials_logged' => false,
            ],
            'response' => [
                'api_status_code' => $result['api_status_code'] ?? null,
                'api_status_message' => $result['api_status_message'] ?? null,
                'statuses_safe' => $result['statuses'] ?? [],
                'status_count' => $result['status_count'] ?? 0,
                'raw_top_level_keys' => $result['raw_top_level_keys'] ?? [],
                'list_items_safe_shape' => $result['list_items_safe_shape'] ?? [],
            ],
            'ovoko_write' => false,
            'local_update' => false,
            'will_import_part' => false,
            'will_update_part' => false,
            'will_sync_stock' => false,
            'will_import_orders' => false,
        ]);
    }
}

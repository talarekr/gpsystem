<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TestOvokoApiConnectionController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const ENDPOINT_PATH = '/v2/get/parts';

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_message' => 'Invalid diagnostics token.',
            ], 403);
        }

        $checkedAt = now();
        $account = Schema::hasTable('marketplace_accounts')
            ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first()
            : null;

        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $credentialsConfigured = filled($credentials['username'] ?? null)
            && filled($credentials['password'] ?? null)
            && filled($credentials['user_token'] ?? null);

        $baseResponse = [
            'ok' => false,
            'dry_run' => true,
            'api_enabled' => (bool) ($account?->api_enabled ?? false),
            'api_base_url' => $account?->api_base_url,
            'api_mode' => $account?->api_mode,
            'credentials_configured' => $credentialsConfigured,
            'http_status' => null,
            'api_status_code' => null,
            'api_status_message' => null,
            'connection_ok' => false,
            'endpoint_used' => $this->endpointUsed((string) ($account?->api_base_url ?? '')),
            'request_method' => 'POST',
            'request_format' => 'form-data',
            'response_sample_safe' => null,
            'last_connection_check_at' => $checkedAt->toISOString(),
        ];

        if ($account === null) {
            return response()->json($baseResponse + [
                'api_status_message' => 'Marketplace account ovoko_main was not found.',
            ], 404);
        }

        $validationMessage = $this->validateConfiguration($account, $credentialsConfigured);
        if ($validationMessage !== null) {
            $this->storeStatus($account, $checkedAt, 'failed', $validationMessage);

            return response()->json(array_merge($baseResponse, [
                'api_status_message' => $validationMessage,
            ]), 422);
        }

        try {
            $endpointUrl = rtrim((string) $account->api_base_url, '/').self::ENDPOINT_PATH;
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(15)
                ->post($endpointUrl.'?limit=1&page=1', [
                    'username' => (string) $credentials['username'],
                    'password' => (string) $credentials['password'],
                    'user_token' => (string) $credentials['user_token'],
                ]);

            $json = $response->json();
            $payload = is_array($json) ? $json : [];
            $apiStatusCode = $payload['status_code'] ?? null;
            $apiStatusMessage = $payload['msg'] ?? ($payload['message'] ?? null);
            $connectionOk = $response->successful() && $apiStatusCode === 'R200';
            $status = $connectionOk ? 'ok' : 'failed';
            $message = $connectionOk
                ? 'Ovoko/RRR API connection test succeeded.'
                : $this->safeFailureMessage($apiStatusCode, $apiStatusMessage, $response->status());

            $this->storeStatus($account, $checkedAt, $status, $message);

            return response()->json(array_merge($baseResponse, [
                'ok' => true,
                'http_status' => $response->status(),
                'api_status_code' => $apiStatusCode,
                'api_status_message' => $apiStatusMessage,
                'connection_ok' => $connectionOk,
                'response_sample_safe' => $this->safeResponseSample($payload),
            ]));
        } catch (ConnectionException $exception) {
            return $this->exceptionResponse($account, $checkedAt, $baseResponse, 'Ovoko/RRR API connection timed out or failed.');
        } catch (Throwable $exception) {
            return $this->exceptionResponse($account, $checkedAt, $baseResponse, 'Ovoko/RRR API connection test failed unexpectedly.');
        }
    }

    private function validateConfiguration(MarketplaceAccount $account, bool $credentialsConfigured): ?string
    {
        if (! $account->api_enabled) {
            return 'Ovoko API is not enabled for ovoko_main.';
        }

        if (blank($account->api_base_url)) {
            return 'Ovoko API base URL is missing.';
        }

        if ($account->api_mode !== 'dry_run') {
            return 'Ovoko API connection test is allowed only in dry_run mode.';
        }

        if (! $credentialsConfigured) {
            return 'Ovoko API credentials are not fully configured.';
        }

        return null;
    }

    private function endpointUsed(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').self::ENDPOINT_PATH.'?limit=1&page=1';
    }

    private function safeResponseSample(array $payload): array
    {
        return [
            'status_code' => $payload['status_code'] ?? null,
            'msg' => $payload['msg'] ?? null,
            'data_count' => is_countable($payload['data'] ?? null) ? count($payload['data']) : null,
            'pagination' => Arr::only((array) ($payload['pagination'] ?? []), ['page', 'limit', 'total_count']),
            'top_level_keys' => array_values(array_slice(array_keys($payload), 0, 20)),
        ];
    }

    private function safeFailureMessage(mixed $apiStatusCode, mixed $apiStatusMessage, int $httpStatus): string
    {
        $message = filled($apiStatusMessage) ? (string) $apiStatusMessage : 'Ovoko/RRR API returned a non-success status.';

        return "HTTP {$httpStatus}; API status ".($apiStatusCode ?: 'missing').'; '.$message;
    }

    private function exceptionResponse(MarketplaceAccount $account, $checkedAt, array $baseResponse, string $message): JsonResponse
    {
        $this->storeStatus($account, $checkedAt, 'failed', $message);

        return response()->json(array_merge($baseResponse, [
            'api_status_message' => $message,
        ]), 502);
    }

    private function storeStatus(MarketplaceAccount $account, $checkedAt, string $status, string $message): void
    {
        $account->forceFill([
            'last_connection_check_at' => $checkedAt,
            'last_connection_status' => $status,
            'last_connection_message' => $message,
        ])->save();
    }
}

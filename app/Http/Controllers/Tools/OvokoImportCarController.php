<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\MarketplaceAccount;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Marketplace\Api\OvokoApiClient;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class OvokoImportCarController extends Controller
{
    public const CONFIRM = 'import-ovoko-car';
    public const MARKER = 'ovoko_import_car_admin_tool_v1';

    public function __invoke(Request $request, OvokoCarDictionaryService $dictionaryService, MarketplaceApiManager $apiManager): JsonResponse
    {
        $data = $request->validate([
            'car_id' => ['required', 'integer', 'min:1'],
            'confirm' => ['required', 'string'],
        ]);

        if ((string) $data['confirm'] !== self::CONFIRM) {
            return response()->json(['ok' => false, 'blocked' => true, 'reason' => 'invalid_confirm', 'marker' => self::MARKER], 422);
        }

        $car = Car::query()->findOrFail((int) $data['car_id']);
        $readiness = $dictionaryService->readiness($car);

        if (filled($readiness['ovoko_car_id'] ?? null)) {
            return response()->json([
                'ok' => false,
                'blocked' => true,
                'reason' => 'local_car_already_has_ovoko_car_id',
                'local_car_id' => $car->id,
                'ovoko_car_id' => (string) $readiness['ovoko_car_id'],
                'marker' => self::MARKER,
            ]);
        }

        if (! (bool) ($readiness['ready_for_future_import_car'] ?? false)) {
            return response()->json([
                'ok' => false,
                'blocked' => true,
                'reason' => 'local_car_not_ready_for_import_car',
                'local_car_id' => $car->id,
                'missing_fields_for_future_import_car' => $readiness['missing_fields_for_future_import_car'] ?? [],
                'readiness' => $readiness,
                'marker' => self::MARKER,
            ], 422);
        }

        $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $missingCredentials = array_values(array_filter(['username', 'password', 'user_token'], fn (string $key): bool => blank($credentials[$key] ?? null)));

        if (! $account || blank($account->api_base_url) || $missingCredentials !== []) {
            return response()->json([
                'ok' => false,
                'blocked' => true,
                'reason' => 'ovoko_api_credentials_missing',
                'local_car_id' => $car->id,
                'missing_credentials' => $missingCredentials,
                'api_base_url_configured' => filled($account?->api_base_url),
                'marker' => self::MARKER,
            ], 422);
        }

        $payload = Arr::only((array) ($readiness['planned_import_car_payload'] ?? []), ['car_model', 'car_years', 'status', 'external_id', 'car_fuel', 'car_engine_code', 'vin', 'mileage']);

        if (blank($payload['status'] ?? null)) {
            return response()->json([
                'ok' => false,
                'blocked' => true,
                'reason' => 'ovoko_status_id_missing_for_import_car',
                'local_car_id' => $car->id,
                'missing_fields_for_future_import_car' => ['ovoko_status_id'],
                'request_payload_without_auth' => $payload,
                'readiness' => $readiness,
                'marker' => self::MARKER,
            ], 422);
        }

        try {
            /** @var OvokoApiClient $client */
            $client = $apiManager->client('ovoko');
            $result = $client->importCar($payload);
        } catch (Throwable $e) {
            $this->storeDiagnostic($car, $payload, ['exception' => $this->safeError($e)], false);

            return response()->json([
                'ok' => false,
                'local_car_id' => $car->id,
                'reason' => 'ovoko_import_car_request_failed',
                'message' => $this->safeError($e),
                'external_id' => $payload['external_id'] ?? null,
                'request_payload_without_auth' => $payload,
                'marker' => self::MARKER,
            ], 502);
        }

        $ovokoCarId = $result['car_id'] ?? null;
        $ok = (bool) ($result['api_ok'] ?? false) && filled($ovokoCarId);

        if (! $ok) {
            $this->storeDiagnostic($car, $payload, $this->sanitizeResponse($result), false);

            return response()->json([
                'ok' => false,
                'local_car_id' => $car->id,
                'reason' => filled($ovokoCarId) ? 'ovoko_import_car_unsuccessful_status' : 'ovoko_import_car_missing_car_id',
                'status_code' => $result['api_status_code'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'message' => $result['message'] ?? null,
                'external_id' => $payload['external_id'] ?? null,
                'response' => $this->sanitizeResponse($result),
                'request_payload_without_auth' => $payload,
                'marker' => self::MARKER,
            ], 502);
        }

        DB::transaction(function () use ($car, $payload, $result, $ovokoCarId): void {
            $locked = Car::query()->lockForUpdate()->findOrFail($car->id);
            $legacy = is_array($locked->legacy_payload) ? $locked->legacy_payload : [];
            $legacy['ovoko_car_id'] = (string) $ovokoCarId;
            $legacy['import_car_request_payload'] = $payload;
            $legacy['import_car_response_payload'] = $this->sanitizeResponse($result);
            $legacy['imported_at'] = now()->toISOString();
            $legacy['external_id'] = $payload['external_id'] ?? null;
            $legacy['status_code'] = $result['api_status_code'] ?? null;
            $legacy['ovoko_import_car_marker'] = self::MARKER;
            $locked->forceFill(['legacy_payload' => $legacy])->save();
        });

        return response()->json([
            'ok' => true,
            'local_car_id' => $car->id,
            'ovoko_car_id' => (string) $ovokoCarId,
            'status_code' => $result['api_status_code'] ?? null,
            'external_id' => $payload['external_id'] ?? null,
            'idempotent_existing_car' => $this->looksIdempotentExistingCar($result),
            'request_payload_without_auth' => $payload,
            'marker' => self::MARKER,
        ]);
    }

    private function storeDiagnostic(Car $car, array $requestPayload, array $responsePayload, bool $success): void
    {
        $legacy = is_array($car->legacy_payload) ? $car->legacy_payload : [];
        $legacy['import_car_request_payload'] = $requestPayload;
        $legacy['import_car_response_payload'] = $responsePayload;
        $legacy['import_car_failed_at'] = $success ? null : now()->toISOString();
        $legacy['ovoko_import_car_marker'] = self::MARKER;
        $car->forceFill(['legacy_payload' => $legacy])->save();
    }

    private function sanitizeResponse(array $result): array
    {
        return Arr::except($result, ['endpoint_used']);
    }

    private function looksIdempotentExistingCar(array $result): bool
    {
        $message = strtolower((string) ($result['message'] ?? data_get($result, 'payload.msg') ?? data_get($result, 'payload.message') ?? ''));
        return str_contains($message, 'exist') || str_contains($message, 'already');
    }

    private function safeError(Throwable $e): string
    {
        return str($e->getMessage())->replaceMatches('/(password|user_token|token|secret|authorization|username)=([^&\s]+)/i', '$1=***')->limit(500)->toString();
    }
}

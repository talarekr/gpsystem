<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class OvokoImportCarPayloadPreviewController extends Controller
{
    public const MARKER = 'ovoko_import_car_payload_preview_v1';

    /**
     * Read-only preview of the future POST /crm/importCar payload.
     *
     * This endpoint intentionally does not call Ovoko, does not call importCar(),
     * and does not mutate the local car or its legacy_payload.
     */
    public function __invoke(Request $request, OvokoCarDictionaryService $dictionaryService): JsonResponse
    {
        $carId = (int) $request->query('car_id');
        $car = Car::query()->find($carId);

        if (! $car) {
            return response()->json([
                'ok' => false,
                'marker' => self::MARKER,
                'reason' => 'local_car_not_found',
                'local_car_id' => $carId > 0 ? $carId : null,
                'safety_flags' => $this->safetyFlags(),
            ], 404);
        }

        $readiness = $dictionaryService->readiness($car);
        $plannedPayload = (array) ($readiness['planned_import_car_payload'] ?? []);

        $minimalPayload = Arr::only($plannedPayload, [
            'car_model',
            'car_years',
            'status',
            'external_id',
        ]);

        $extendedConfirmedPayload = Arr::only($plannedPayload, [
            'car_model',
            'car_years',
            'status',
            'external_id',
            'car_fuel',
            'car_engine_code',
            'vin',
            'mileage',
        ]);

        $response = [
            'ok' => true,
            'marker' => self::MARKER,
            'local_car_id' => $car->id,
            'ready_for_future_import_car' => (bool) ($readiness['ready_for_future_import_car'] ?? false),
            'minimal_payload' => $minimalPayload,
            'extended_confirmed_payload' => $extendedConfirmedPayload,
            'skipped_fields' => $this->skippedFields($car, $readiness),
            'safety_flags' => $this->safetyFlags(),
        ];

        if (! (bool) ($readiness['ready_for_future_import_car'] ?? false)) {
            $response['missing_fields_for_future_import_car'] = $readiness['missing_fields_for_future_import_car'] ?? [];
            $response['readiness_marker'] = $readiness['marker'] ?? null;
        }

        return response()->json($response);
    }

    private function skippedFields(Car $car, array $readiness): array
    {
        $mappings = (array) ($readiness['ovoko_mappings'] ?? []);

        return [
            'gearbox' => [
                'source' => 'legacy_payload.ovoko_gearbox_type_id',
                'value' => $mappings['ovoko_gearbox_type_id'] ?? null,
                'reason' => filled($mappings['ovoko_gearbox_type_id'] ?? null) ? 'missing_confirmed_api_param' : 'missing_value',
            ],
            'body_type' => [
                'source' => 'legacy_payload.ovoko_body_type_id',
                'value' => $mappings['ovoko_body_type_id'] ?? null,
                'reason' => filled($mappings['ovoko_body_type_id'] ?? null) ? 'missing_confirmed_api_param' : 'missing_value',
            ],
            'wheel_drive' => [
                'source' => 'legacy_payload.ovoko_wheel_drive_id',
                'value' => $mappings['ovoko_wheel_drive_id'] ?? null,
                'reason' => filled($mappings['ovoko_wheel_drive_id'] ?? null) ? 'missing_confirmed_api_param' : 'missing_value',
            ],
            'wheel' => [
                'source' => 'legacy_payload.ovoko_wheel_id',
                'value' => $mappings['ovoko_wheel_id'] ?? null,
                'reason' => filled($mappings['ovoko_wheel_id'] ?? null) ? 'missing_confirmed_api_param' : 'missing_value',
            ],
            'color' => [
                'source' => 'color',
                'value' => $car->color,
                'reason' => 'missing_confirmed_api_param_or_no_ovoko_dictionary',
            ],
            'color_code' => [
                'source' => 'color_code',
                'value' => $car->color_code,
                'reason' => 'missing_confirmed_api_param',
            ],
            'engine_power_kw' => [
                'source' => 'engine_power_kw',
                'value' => $car->engine_power_kw,
                'reason' => 'missing_confirmed_api_param',
            ],
            'engine_capacity_cm3' => [
                'source' => 'engine_capacity_cm3',
                'value' => $car->engine_capacity_cm3,
                'reason' => 'missing_confirmed_api_param',
            ],
        ];
    }

    private function safetyFlags(): array
    {
        return [
            'read_only' => true,
            'no_ovoko_request' => true,
            'no_import_car' => true,
            'no_mutation' => true,
        ];
    }
}

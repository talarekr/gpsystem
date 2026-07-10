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
            'car_gearbox_type',
            'car_body_type',
            'car_wheel_drive',
            'car_wheel_type',
            'car_engine_cubic_capacity',
            'car_engine_power',
            'car_mileage',
            'car_engine_code',
            'car_gearbox_code',
            'car_color',
            'car_color_code',
            'car_interior',
            'car_price',
            'defectation_notes',
            'purchase_date',
            'dismantling_at',
            'car_body_number',
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
        return (array) ($readiness['skipped_optional_fields'] ?? []);
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

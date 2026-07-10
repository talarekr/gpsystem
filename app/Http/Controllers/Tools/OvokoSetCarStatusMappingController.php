<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\OvokoCarDictionaryEntry;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OvokoSetCarStatusMappingController extends Controller
{
    public const CONFIRM = 'set-ovoko-car-status';

    public function __invoke(Request $request, OvokoCarDictionaryService $service): JsonResponse
    {
        $data = $request->validate([
            'car_id' => ['required', 'integer', 'exists:cars,id'],
            'ovoko_status_id' => ['required', 'string', 'max:64'],
            'confirm' => ['required', 'string', 'in:'.self::CONFIRM],
        ]);

        $statusId = trim((string) $data['ovoko_status_id']);
        $statusExists = OvokoCarDictionaryEntry::query()
            ->where('dictionary', 'car_status')
            ->where('ovoko_id', $statusId)
            ->exists();

        if (! $statusExists) {
            throw ValidationException::withMessages([
                'ovoko_status_id' => 'Selected Ovoko car status does not exist in the local car_status cache.',
            ]);
        }

        $car = Car::query()->findOrFail((int) $data['car_id']);
        $before = $car->legacy_payload ?? [];
        $payload = is_array($before) ? $before : [];
        $previousStatusId = $payload['ovoko_status_id'] ?? null;
        $payload['ovoko_status_id'] = $statusId;

        $car->forceFill(['legacy_payload' => $payload])->save();
        $car->refresh();

        return response()->json([
            'ok' => true,
            'marker' => OvokoCarDictionaryService::CAR_STATUS_MAPPING_READINESS_MARKER,
            'local_car_id' => $car->id,
            'previous_ovoko_status_id' => $previousStatusId,
            'ovoko_status_id' => $statusId,
            'readiness' => $service->readiness($car),
            'safety_flags' => [
                'single_car_only' => true,
                'writes_only_legacy_payload_ovoko_status_id' => true,
                'no_import_car' => true,
                'no_import_part' => true,
                'no_parts_mutation' => true,
                'no_bulk_update' => true,
            ],
        ]);
    }
}

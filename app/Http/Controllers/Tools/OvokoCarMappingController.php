<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\Api\OvokoApiClient;
use App\Services\Marketplace\MarketplaceApiManager;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class OvokoCarMappingController extends Controller
{
    public function dryRun(Car $car): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->buildDryRun($car));
    }

    public function apply(Request $request, Car $car): \Illuminate\Http\JsonResponse
    {
        if ($request->query('confirm') !== 'ovoko-car-map') {
            return response()->json(['ok' => false, 'blocked' => true, 'reason' => 'missing_confirm_token', 'expected_confirm' => 'ovoko-car-map'], 422);
        }

        $dryRun = $this->buildDryRun($car);
        $requested = trim((string) $request->input('ovoko_car_id', $request->query('ovoko_car_id', '')));

        if ($requested === '' && count($dryRun['search_candidates']) === 1) {
            $requested = (string) $dryRun['search_candidates'][0]['ovoko_car_id'];
        }

        if ($requested === '' && $dryRun['can_create_ovoko_car']) {
            /** @var OvokoApiClient $client */
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            $result = $client->importCar($dryRun['would_create_payload']);
            $requested = trim((string) ($result['car_id'] ?? ''));
            $dryRun['create_result'] = Arr::except($result, ['payload']);
        }

        if ($requested === '') {
            return response()->json($dryRun + ['ok' => false, 'blocked' => true, 'reason' => 'ovoko_car_id_required_manual_input'], 422);
        }

        $car->forceFill(['source_system' => $car->source_system ?: 'ovoko', 'external_id' => $requested])->save();

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'ovoko',
            'action' => 'ovoko_car_mapping_apply',
            'status' => 'success',
            'message' => 'Mapped local car to Ovoko/RRR car_id without publishing parts.',
            'payload' => ['local_car_id' => $car->id, 'ovoko_car_id' => $requested, 'dry_run' => $dryRun, 'safety_flags' => $dryRun['safety_flags']],
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'local_car_id' => $car->id, 'ovoko_car_id' => $requested, 'no_part_publish' => true]);
    }

    private function buildDryRun(Car $car): array
    {
        $payload = $this->createPayload($car);
        $missing = array_values(array_filter(['make', 'model', 'year'], fn (string $field): bool => blank($payload[$field] ?? null)));
        $candidates = [];
        $canSearch = true;

        try {
            /** @var OvokoApiClient $client */
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            $candidates = $client->searchCars($car->vin, $car->external_id, $payload);
        } catch (\Throwable $e) {
            $canSearch = false;
            $candidates = [['error' => 'ovoko_car_search_unavailable', 'exception' => $e::class]];
        }

        return [
            'local_car_id' => $car->id,
            'make' => $car->make,
            'model' => $car->model,
            'year' => $car->production_year ?? $car->first_registration_year,
            'vin' => $car->vin,
            'engine_code' => $car->engine_code,
            'fuel_type' => $car->fuel_type,
            'mileage' => $car->mileage_km,
            'existing_ovoko_car_id' => $car->external_id,
            'can_search_ovoko_car' => $canSearch,
            'search_candidates' => $candidates,
            'can_create_ovoko_car' => $missing === [],
            'required_fields_missing' => $missing,
            'would_create_payload' => $payload,
            'safety_flags' => ['dry_run' => true, 'no_part_publish' => true, 'no_stock' => true, 'no_price' => true, 'no_orders' => true],
            'api_research' => [
                'public_docs_found' => false,
                'implemented_candidate_create_endpoint' => '/crm/importCar',
                'implemented_candidate_search_endpoint' => '/v2/get/cars',
                'note' => 'Public web search did not reveal official Ovoko/RRR car import documentation; endpoints are isolated behind dry-run/apply safeguards and must be validated with seller API credentials.',
            ],
        ];
    }

    private function createPayload(Car $car): array
    {
        return array_filter([
            'make' => $car->make,
            'model' => $car->model,
            'year' => $car->production_year ?? $car->first_registration_year,
            'vin' => $car->vin,
            'engine_code' => $car->engine_code,
            'fuel_type' => $car->fuel_type,
            'mileage' => $car->mileage_km,
            'external_id' => 'gps-car-'.$car->id,
        ], fn ($value): bool => filled($value));
    }
}

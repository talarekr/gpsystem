<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\Api\OvokoApiClient;
use App\Services\Marketplace\Api\MarketplaceApiManager;
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

        if ($requested === '' && empty($dryRun['existing_car_search_unavailable']) && count($dryRun['search_candidates']) === 1) {
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
        $searchSupported = true;
        $searchWarning = null;
        $searchDiagnostics = [
            'search_endpoint' => null,
            'search_request_fields' => [],
            'search_request_payload' => [],
            'search_response_top_level_keys' => [],
            'search_response_sample_raw' => [],
            'search_filter_applied' => false,
            'search_filter_ignored' => false,
            'returned_candidates_count' => 0,
            'parsed_candidates_count' => 0,
            'usable_candidates_count' => 0,
        ];

        try {
            /** @var OvokoApiClient $client */
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            $searchDiagnostics = $client->searchCarsDiagnostics($car->vin, $car->external_id, $payload);
            $candidates = $searchDiagnostics['usable_candidates'];
            $searchSupported = (bool) ($searchDiagnostics['api_ok'] ?? false);
            $canSearch = $searchSupported;

            if (($searchDiagnostics['search_filter_ignored'] ?? false) || ($searchDiagnostics['returned_candidates_count'] ?? 0) > 0 && ($searchDiagnostics['usable_candidates_count'] ?? 0) === 0) {
                $searchSupported = false;
                $canSearch = false;
                $candidates = [];
                $searchWarning = 'ovoko_car_search_filter_ignored_or_unusable';
            }
        } catch (\Throwable $e) {
            $canSearch = false;
            $searchSupported = false;
            $searchWarning = 'ovoko_car_search_unavailable';
            $searchDiagnostics['error'] = [
                'error_class' => $e::class,
                'error_message' => $this->safeErrorMessage($e),
                'read_only' => true,
            ];
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
            'search_supported' => $searchSupported,
            'search_candidates' => $candidates,
            'search_warning' => $searchWarning,
            'existing_car_search_unavailable' => ! $searchSupported,
            'apply_will_create_new_car' => ! $searchSupported && $missing === [],
            'search_endpoint' => $searchDiagnostics['search_endpoint'] ?? null,
            'search_request_fields' => $searchDiagnostics['search_request_fields'] ?? [],
            'search_request_payload' => $searchDiagnostics['search_request_payload'] ?? [],
            'search_response_top_level_keys' => $searchDiagnostics['search_response_top_level_keys'] ?? [],
            'search_response_sample_raw' => $searchDiagnostics['search_response_sample_raw'] ?? [],
            'search_filter_applied' => $searchDiagnostics['search_filter_applied'] ?? false,
            'search_filter_ignored' => $searchDiagnostics['search_filter_ignored'] ?? false,
            'returned_candidates_count' => $searchDiagnostics['returned_candidates_count'] ?? 0,
            'parsed_candidates_count' => $searchDiagnostics['parsed_candidates_count'] ?? 0,
            'usable_candidates_count' => $searchDiagnostics['usable_candidates_count'] ?? 0,
            'parsed_search_candidates' => $searchDiagnostics['all_candidates'] ?? [],
            'can_create_ovoko_car' => $missing === [],
            'required_fields_missing' => $missing,
            'would_create_payload' => $payload,
            'safety_flags' => ['dry_run' => true, 'no_part_publish' => true, 'no_stock' => true, 'no_price' => true, 'no_orders' => true],
            'api_research' => [
                'public_docs_found' => false,
                'implemented_candidate_create_endpoint' => '/crm/importCar',
                'implemented_candidate_search_endpoint' => '/v2/get/cars',
                'checked_alternative_lookup_fields' => ['external_id', 'user_code', 'vin', 'id'],
                'reliable_alternative_search_endpoint_found' => false,
                'note' => 'Public web search did not reveal official Ovoko/RRR car import documentation; endpoints are isolated behind dry-run/apply safeguards and must be validated with seller API credentials.',
            ],
        ];
    }

    private function safeErrorMessage(\Throwable $e): string
    {
        return str($e->getMessage())->replaceMatches('/(password|user_token|token|secret|authorization|username)=([^&\s]+)/i', '$1=***')->limit(500)->toString();
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

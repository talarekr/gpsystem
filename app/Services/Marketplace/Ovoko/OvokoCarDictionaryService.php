<?php

namespace App\Services\Marketplace\Ovoko;

use App\Models\Car;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\OvokoCarDictionaryEntry;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OvokoCarDictionaryService
{
    public const MARKER = 'ovoko_car_dictionaries_cache_diagnostics_v1';
    public const CONFIRM = 'sync-ovoko-car-dictionaries';
    public const DICTIONARIES = ['brands', 'models', 'fuel', 'gearbox_type', 'body_type', 'wheel', 'wheel_drive', 'car_status', 'car_class'];
    public const ENUMS = ['fuel', 'gearbox_type', 'body_type', 'wheel', 'wheel_drive', 'car_status', 'car_class'];

    public function diagnostics(?string $brandSearch = null, ?string $brandId = null, int $modelsLimit = 5, bool $includeRaw = false): array
    {
        $account = $this->account();
        $credentials = $account?->api_credentials ?? [];
        $counts = [];
        $lastSynced = [];
        foreach (self::DICTIONARIES as $dictionary) {
            $query = OvokoCarDictionaryEntry::query()->where('dictionary', $dictionary);
            $counts[$dictionary] = (clone $query)->count();
            $lastSynced[$dictionary] = (clone $query)->max('synced_at');
        }

        $sampleBrand = $this->sampleBrand($brandId);

        return [
            'ok' => true,
            'marker' => self::MARKER,
            'credentials' => [
                'account_exists' => $account !== null,
                'api_enabled' => (bool) ($account?->api_enabled ?? false),
                'api_base_url_configured' => filled($account?->api_base_url),
                'username_configured' => filled($credentials['username'] ?? null),
                'password_configured' => filled($credentials['password'] ?? null),
                'user_token_configured' => filled($credentials['user_token'] ?? null),
                'credentials_configured' => filled($credentials['username'] ?? null) && filled($credentials['password'] ?? null) && filled($credentials['user_token'] ?? null),
            ],
            'supported_dictionaries' => self::DICTIONARIES,
            'local_cache' => ['table' => 'ovoko_car_dictionary_entries', 'exists' => Schema::hasTable('ovoko_car_dictionary_entries')],
            'counts' => $counts,
            'brands_count' => $counts['brands'] ?? 0,
            'models_count' => $counts['models'] ?? 0,
            'last_synced_at' => $lastSynced,
            'samples' => [
                'brands' => $this->sample('brands'),
                'models_for_brand' => $sampleBrand ? [
                    'ovoko_brand_id' => (string) $sampleBrand->ovoko_id,
                    'brand_name' => $sampleBrand->name,
                    'models_count' => OvokoCarDictionaryEntry::query()->where('dictionary', 'models')->where('ovoko_brand_id', (string) $sampleBrand->ovoko_id)->count(),
                    'models' => $this->sample('models', (string) $sampleBrand->ovoko_id, $modelsLimit, $includeRaw),
                ] : null,
            ],
            'last_sync_error' => $this->lastSyncError(),
            'brand_search' => $this->brandSearchDiagnostics($brandSearch),
            'model_modification_diagnostics' => $this->modelModificationDiagnostics(),
            'import_car_model_requirements' => $this->importCarModelRequirements(),
            'safety_flags' => ['read_only_diagnose' => true, 'no_import_car' => true, 'no_import_part' => true, 'no_parts_mutation' => true, 'no_local_cars_mutation' => true],
        ];
    }

    public function sync(string $scope, ?string $brandId = null): array
    {
        $scope = in_array($scope, ['all', 'brands', 'models', 'enums'], true) ? $scope : 'all';
        /** @var OvokoApiClient $client */
        $client = app(MarketplaceApiManager::class)->client('ovoko');
        $summary = ['scope' => $scope, 'brand_id' => $brandId, 'synced' => [], 'errors' => [], 'models_mode' => null, 'safety_flags' => ['no_import_car' => true, 'no_import_part' => true]];

        foreach ($this->scopeDictionaries($scope, $brandId) as $dictionary) {
            try {
                if ($dictionary === 'models') {
                    $brands = $brandId ? collect([(object) ['ovoko_id' => $brandId]]) : collect();
                    if (! $brandId && $scope === 'all') {
                        $summary['models_mode'] = 'skipped_for_all_scope_sync_models_per_brand_to_avoid_hundreds_of_requests';
                        continue;
                    }
                    if (! $brandId) $brands = OvokoCarDictionaryEntry::query()->where('dictionary', 'brands')->get(['ovoko_id']);
                    foreach ($brands as $brand) $summary['synced']['models'][$brand->ovoko_id] = $this->storeRows('models', $client->fetchCarDictionary('models', (string) $brand->ovoko_id)['rows'], (string) $brand->ovoko_id);
                    $summary['models_mode'] ??= 'per_brand';
                    continue;
                }
                $summary['synced'][$dictionary] = $this->storeRows($dictionary, $client->fetchCarDictionary($dictionary)['rows']);
            } catch (\Throwable $e) {
                $summary['errors'][$dictionary] = $this->safeError($e);
                $this->log('error', 'ovoko_car_dictionary_sync_error', $summary['errors'][$dictionary], ['dictionary' => $dictionary]);
            }
        }
        $this->log(empty($summary['errors']) ? 'success' : 'warning', 'ovoko_car_dictionary_sync', 'Ovoko car dictionaries sync finished.', $summary);
        return $summary;
    }

    public function readiness(Car $car): array
    {
        $ids = $this->mappedIds($car);
        $exists = [];
        foreach ($ids as $key => $value) $exists[$key] = filled($value) ? $this->idExists($key, (string) $value, $ids['ovoko_brand_id'] ?? null) : false;
        $payload = array_filter([
            'car_model' => $ids['ovoko_car_model_id'] ?? null,
            'car_years' => $car->production_year ?? $car->first_registration_year,
            'car_fuel' => $ids['ovoko_fuel_id'] ?? null,
            'car_engine_code' => $car->engine_code,
            'vin' => $car->vin,
            'mileage' => $car->mileage_km,
            'external_id' => 'gps-car-'.$car->id,
        ], fn ($value): bool => filled($value));
        $missing = array_values(array_filter(['ovoko_car_model_id'], fn ($field) => blank($ids[$field] ?? null) || ! ($exists[$field] ?? false)));

        return [
            'ok' => true,
            'marker' => self::MARKER,
            'local_car_id' => $car->id,
            'ovoko_car_id' => $ids['ovoko_car_id'],
            'ovoko_car_id_set' => filled($ids['ovoko_car_id']),
            'looks_historically_imported_from_ovoko' => filled($ids['ovoko_car_id']) && (($car->source_system === 'ovoko') || ((string) $car->external_id === (string) $ids['ovoko_car_id'])),
            'local' => ['make' => $car->make, 'model' => $car->model, 'year' => $car->production_year ?? $car->first_registration_year, 'fuel_type' => $car->fuel_type, 'gearbox_type' => $car->gearbox_type, 'body_type' => $car->body_type, 'steering_side' => $car->steering_side, 'drivetrain' => $car->drivetrain, 'status' => $car->status],
            'ovoko_mappings' => $ids,
            'mapping_ids_exist_in_cache' => $exists,
            'missing_fields_for_future_import_car' => $missing,
            'ready_for_future_import_car' => $missing === [],
            'planned_import_car_payload' => $payload,
            'safety_flags' => ['read_only' => true, 'no_import_car' => true, 'no_import_part' => true, 'no_mutation' => true],
        ];
    }

    private function brandSearchDiagnostics(?string $query): ?array
    {
        $query = trim((string) $query);
        if ($query === '') {
            return null;
        }

        $matches = OvokoCarDictionaryEntry::query()
            ->where('dictionary', 'brands')
            ->where('name', 'like', '%'.$query.'%')
            ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END', [strtolower($query)])
            ->orderBy('name')
            ->limit(10)
            ->get(['ovoko_id', 'name', 'synced_at'])
            ->map(fn (OvokoCarDictionaryEntry $brand): array => [
                'ovoko_id' => (string) $brand->ovoko_id,
                'name' => $brand->name,
                'synced_at' => optional($brand->synced_at)->toISOString(),
                'models_count_in_cache' => OvokoCarDictionaryEntry::query()
                    ->where('dictionary', 'models')
                    ->where('ovoko_brand_id', (string) $brand->ovoko_id)
                    ->count(),
            ])
            ->values()
            ->all();

        return ['query' => $query, 'matches' => $matches];
    }

    private function modelModificationDiagnostics(): array
    {
        $modelRowsWithYears = OvokoCarDictionaryEntry::query()
            ->where('dictionary', 'models')
            ->where(function ($query): void {
                $query->whereNotNull('year_from')->orWhereNotNull('year_to');
            })
            ->count();

        return [
            'known_endpoint' => null,
            'car_models_endpoint' => '/get/car_models/{brand_id}',
            'car_models_endpoint_may_represent' => 'unknown',
            'static_code_review' => [
                'client_dictionary_paths' => [
                    'brands' => '/get/car_brands',
                    'models' => '/get/car_models/{brand_id}',
                ],
                'separate_general_model_endpoint_found' => false,
                'separate_modification_endpoint_found' => false,
                'local_cache_parent_or_series_columns_found' => false,
                'cached_raw_payload_available_for_review' => true,
            ],
            'cache_models_with_years_count' => $modelRowsWithYears,
            'notes' => 'Static code review found only /get/car_models/{brand_id} for the cached models dictionary. The local cache has no dedicated parent/model/series columns, but raw_payload can now be included with include_raw=1 to inspect whether Ovoko returns such fields. No separate local endpoint/client method for a general model level or a modification level was found, and this read-only diagnostic does not call Ovoko, import cars, import parts, or mutate local data.',
        ];
    }


    private function importCarModelRequirements(): array
    {
        return [
            'source' => 'official_docs_and_static_code_review',
            'required_fields_from_docs' => ['car_model'],
            'car_model_field_meaning' => 'modification_or_generation',
            'car_model_expected_source' => '/get/car_models/{brand_id}',
            'separate_general_model_field_found' => false,
            'separate_modification_field_found' => false,
            'separate_general_model_endpoint_found' => false,
            'separate_modification_endpoint_found' => false,
            'docs_example' => [
                'endpoint' => 'POST /crm/importCar',
                'fields' => [
                    'car_model' => '1548',
                    'car_years' => '2004',
                    'car_fuel' => '1',
                    'external_id' => 'gps-car-123',
                ],
                'car_model_example_matches_cached_car_models_id' => true,
            ],
            'notes' => 'Official Ovoko/RRR importCar documentation and the current client surface expose car_model as the required model identifier for POST /crm/importCar. The only implemented/read-only dictionary source for that identifier is /get/car_models/{brand_id}. Those dictionary rows include generation-like names and years (for example BMW 3 E46 / X4 F26 style entries), so this diagnostic treats car_model as the modification/generation-level ID rather than a separate parent model/series label. No documented/importCar field or current client/cache support was found for an additional general-model field separate from car_model, and no separate endpoint for parent/general models or model modifications is implemented in this codebase. This endpoint is read-only and does not call importCar, importPart, or mutate cars/parts.',
        ];
    }

    private function scopeDictionaries(string $scope, ?string $brandId): array { return match ($scope) { 'brands' => ['brands'], 'models' => ['models'], 'enums' => self::ENUMS, default => array_merge(['brands'], self::ENUMS, ['models']) }; }
    private function account(): ?MarketplaceAccount { return MarketplaceAccount::query()->where('code', 'ovoko_main')->first(); }
    private function storeRows(string $dictionary, array $rows, ?string $brandId = null): int { $count = 0; $brandKey = (string) ($brandId ?? ''); foreach ($rows as $row) { $id = (string) ($row['id'] ?? $row['value'] ?? $row['code'] ?? $row['car_model_id'] ?? $row['model_id'] ?? ''); if ($id === '') continue; OvokoCarDictionaryEntry::query()->updateOrCreate(['dictionary' => $dictionary, 'ovoko_id' => $id, 'ovoko_brand_id' => $brandKey], ['name' => $row['name'] ?? $row['title'] ?? $row['label'] ?? $row['value_name'] ?? null, 'year_from' => $row['year_from'] ?? $row['from_year'] ?? null, 'year_to' => $row['year_to'] ?? $row['to_year'] ?? null, 'raw_payload' => $row, 'synced_at' => now()]); $count++; } return $count; }
    private function sampleBrand(?string $brandId = null): ?OvokoCarDictionaryEntry { $brandId = trim((string) $brandId); return OvokoCarDictionaryEntry::query()->where('dictionary', 'brands')->when($brandId !== '', fn ($q) => $q->where('ovoko_id', $brandId))->orderBy('name')->first(); }
    private function sample(string $dictionary, ?string $brandId = null, int $limit = 5, bool $includeRaw = false): array { $limit = max(1, min($limit, 100)); $columns = ['ovoko_id', 'name', 'ovoko_brand_id', 'year_from', 'year_to', 'synced_at']; if ($includeRaw) $columns[] = 'raw_payload'; return OvokoCarDictionaryEntry::query()->where('dictionary', $dictionary)->when($brandId, fn ($q) => $q->where('ovoko_brand_id', $brandId))->orderBy('name')->limit($limit)->get($columns)->toArray(); }
    private function lastSyncError(): ?array { return MarketplaceSyncLog::query()->where('marketplace', 'ovoko')->where('action', 'ovoko_car_dictionary_sync_error')->latest('created_at')->first()?->only(['status', 'message', 'payload', 'created_at']); }
    private function safeError(\Throwable $e): string { return Str::of($e->getMessage())->replaceMatches('/(password|user_token|token|secret|authorization|username)=([^&\s]+)/i', '$1=***')->limit(500)->toString(); }
    private function log(string $status, string $action, string $message, array $payload): void { MarketplaceSyncLog::query()->create(['marketplace' => 'ovoko', 'action' => $action, 'status' => $status, 'message' => $message, 'payload' => $payload, 'created_at' => now()]); }
    private function mappedIds(Car $car): array { $p = $car->legacy_payload ?? []; return ['ovoko_car_id' => $car->getAttribute('ovoko_car_id') ?? $car->external_id ?? data_get($p, 'ovoko_car_id') ?? data_get($p, 'rrr_car_id'), 'ovoko_brand_id' => data_get($p, 'ovoko_brand_id') ?? data_get($p, 'rrr_brand_id'), 'ovoko_car_model_id' => data_get($p, 'ovoko_car_model_id') ?? data_get($p, 'rrr_car_model_id'), 'ovoko_fuel_id' => data_get($p, 'ovoko_fuel_id'), 'ovoko_gearbox_type_id' => data_get($p, 'ovoko_gearbox_type_id'), 'ovoko_body_type_id' => data_get($p, 'ovoko_body_type_id'), 'ovoko_wheel_id' => data_get($p, 'ovoko_wheel_id'), 'ovoko_wheel_drive_id' => data_get($p, 'ovoko_wheel_drive_id'), 'ovoko_status_id' => data_get($p, 'ovoko_status_id')]; }
    private function idExists(string $key, string $id, ?string $brandId = null): bool { $map = ['ovoko_brand_id' => 'brands', 'ovoko_car_model_id' => 'models', 'ovoko_fuel_id' => 'fuel', 'ovoko_gearbox_type_id' => 'gearbox_type', 'ovoko_body_type_id' => 'body_type', 'ovoko_wheel_id' => 'wheel', 'ovoko_wheel_drive_id' => 'wheel_drive', 'ovoko_status_id' => 'car_status']; return isset($map[$key]) && OvokoCarDictionaryEntry::query()->where('dictionary', $map[$key])->where('ovoko_id', $id)->when($map[$key] === 'models' && $brandId, fn ($q) => $q->where('ovoko_brand_id', $brandId))->exists(); }
}

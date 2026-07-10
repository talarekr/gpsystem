<?php

namespace App\Services\Marketplace\Ovoko;

use App\Jobs\SyncOvokoCarModelsBatchJob;
use App\Models\OvokoCarDictionaryEntry;
use App\Models\OvokoCarModelSyncRun;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OvokoCarModelSyncRunnerService
{
    public const MARKER = 'ovoko_car_models_sync_runner_v1';
    public const START_CONFIRM = 'start-ovoko-car-models-sync-runner';
    public const STOP_CONFIRM = 'stop-ovoko-car-models-sync-runner';
    public const RECOVERY_MARKER = 'ovoko_car_models_sync_runner_500_recovery_v6';

    public function start(array $input): array
    {
        $batchSize = min(10, max(1, (int) ($input['batch_size'] ?? 5)));
        $delaySeconds = max(5, (int) ($input['delay_seconds'] ?? 10));
        $onlyMissing = filter_var($input['only_missing'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $onlyMissing = $onlyMissing ?? true;

        if (($input['confirm'] ?? null) !== self::START_CONFIRM) {
            return ['ok' => false, 'marker' => self::MARKER, 'blocked' => true, 'reason' => 'missing_confirm_token', 'expected_confirm' => self::START_CONFIRM];
        }

        $activeRun = $this->activeRun();
        if ($activeRun) {
            return ['ok' => false, 'marker' => self::MARKER, 'blocked' => true, 'reason' => 'active_runner_exists'] + $this->status($activeRun);
        }

        $total = $this->eligibleBrandsQuery($onlyMissing)->count();
        $run = OvokoCarModelSyncRun::query()->create([
            'status' => $total > 0 ? OvokoCarModelSyncRun::STATUS_QUEUED : OvokoCarModelSyncRun::STATUS_COMPLETED,
            'batch_size' => $batchSize,
            'delay_seconds' => $delaySeconds,
            'only_missing' => $onlyMissing,
            'total_brand_count' => $total,
            'processed_brand_count' => 0,
            'synced_models_count' => 0,
            'failed_brand_count' => 0,
            'last_offset' => 0,
            'processed_brand_ids' => [],
            'failed_brands' => [],
            'last_batch' => [],
            'errors' => [],
            'started_at' => now(),
            'completed_at' => $total > 0 ? null : now(),
        ]);

        if ($total > 0) {
            SyncOvokoCarModelsBatchJob::dispatch((int) $run->id);
        }

        return ['ok' => true, 'marker' => self::MARKER, 'run_id' => $run->id] + $this->status($run);
    }

    public function stop(array $input): array
    {
        if (($input['confirm'] ?? null) !== self::STOP_CONFIRM) {
            return ['ok' => false, 'marker' => self::MARKER, 'blocked' => true, 'reason' => 'missing_confirm_token', 'expected_confirm' => self::STOP_CONFIRM];
        }

        $run = $this->activeRun() ?? $this->latestRun();
        if ($run && in_array($run->status, [OvokoCarModelSyncRun::STATUS_RUNNING, OvokoCarModelSyncRun::STATUS_QUEUED], true)) {
            $run->update(['status' => OvokoCarModelSyncRun::STATUS_STOPPED, 'completed_at' => now()]);
        }

        return ['ok' => true, 'marker' => self::MARKER] + $this->status($run?->fresh());
    }

    public function runNextBatch(int $runId): array
    {
        $run = $runId > 0 ? OvokoCarModelSyncRun::query()->find($runId) : null;
        if (! $run || ! in_array($run->status, [OvokoCarModelSyncRun::STATUS_QUEUED, OvokoCarModelSyncRun::STATUS_RUNNING], true)) {
            $run = $this->activeRun();
        }
        if (! $run) {
            return ['ok' => false, 'marker' => self::MARKER, 'reason' => 'runner_not_active'];
        }

        $phase = 'start';
        $batch = ['started_at' => now()->toISOString(), 'brands' => []];

        try {
            $phase = 'refresh_run';
            $run->refresh();
            if (! in_array($run->status, [OvokoCarModelSyncRun::STATUS_QUEUED, OvokoCarModelSyncRun::STATUS_RUNNING], true)) {
                return ['ok' => true, 'marker' => self::MARKER, 'stopped' => true];
            }

            $phase = 'update_run';
            $run->update(['status' => OvokoCarModelSyncRun::STATUS_RUNNING, 'last_batch' => $batch]);

            $phase = 'select_brands';
            $processedIds = $this->normalizeBrandIdList($run->processed_brand_ids);
            $brands = $this->eligibleBrandsQuery((bool) $run->only_missing, $processedIds)->limit((int) $run->batch_size)->get();
            if ($brands->isEmpty()) {
                $batch = ['brand_count' => 0, 'completed' => true, 'finished_at' => now()->toISOString(), 'brands' => []];
                $run->update(['status' => OvokoCarModelSyncRun::STATUS_COMPLETED, 'completed_at' => now(), 'last_batch' => $batch]);
                return ['ok' => true, 'marker' => self::MARKER, 'completed' => true];
            }

            $batch['brand_count'] = $brands->count();
            $synced = 0;
            $failed = $this->normalizeListOfArrays($run->failed_brands);
            $errors = $this->normalizeListOfArrays($run->errors);
            /** @var OvokoApiClient $client */
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            $dictionaryService = app(OvokoCarDictionaryService::class);

            foreach ($brands as $brand) {
                $brandId = $this->normalizeBrandId($brand->ovoko_id);
                $brandName = (string) $brand->name;
                try {
                    $phase = 'fetch_models';
                    $result = $client->fetchCarDictionary('models', $brandId);
                    if (! ($result['api_ok'] ?? false)) {
                        throw new \RuntimeException((string) ($result['error'] ?? 'Ovoko car models request failed.'));
                    }

                    $phase = 'upsert_models';
                    $count = $dictionaryService->storeRows('models', is_array($result['rows'] ?? null) ? $result['rows'] : [], $brandId);
                    $synced += $count;
                    $batch['brands'][] = ['brand_id' => $brandId, 'brand_name' => $brandName, 'status' => 'synced', 'models_count' => $count];
                } catch (\Throwable $e) {
                    $diagnostic = $this->exceptionDiagnostic($e, $phase, ['brand_id' => $brandId, 'brand_name' => $brandName]);
                    $failed[] = $diagnostic;
                    $errors[] = $diagnostic;
                    $batch['brands'][] = ['brand_id' => $brandId, 'brand_name' => $brandName, 'status' => 'failed'] + $diagnostic;
                    Log::warning('Ovoko car models sync runner brand failed.', ['marker' => self::RECOVERY_MARKER, 'run_id' => $run->id] + $diagnostic);
                }
                $processedIds[] = $brandId;
            }

            $phase = 'update_run';
            $processedIds = $this->normalizeBrandIdList($processedIds);
            $remaining = $this->eligibleBrandsQuery((bool) $run->only_missing, $processedIds)->count();
            $batch['finished_at'] = now()->toISOString();
            $batch['remaining_brand_count'] = $remaining;
            $run->update([
                'status' => $remaining > 0 ? OvokoCarModelSyncRun::STATUS_QUEUED : OvokoCarModelSyncRun::STATUS_COMPLETED,
                'processed_brand_count' => count($processedIds),
                'synced_models_count' => ((int) $run->synced_models_count) + $synced,
                'failed_brand_count' => count($failed),
                'last_offset' => count($processedIds),
                'processed_brand_ids' => $processedIds,
                'failed_brands' => $failed,
                'errors' => array_slice($errors, -100),
                'last_batch' => $batch,
                'completed_at' => $remaining > 0 ? null : now(),
            ]);

            if ($remaining > 0) {
                SyncOvokoCarModelsBatchJob::dispatch((int) $run->id)->delay(now()->addSeconds((int) $run->delay_seconds));
            }

            return ['ok' => true, 'marker' => self::MARKER, 'remaining_brand_count' => $remaining, 'last_batch' => $batch];
        } catch (\Throwable $e) {
            $diagnostic = $this->exceptionDiagnostic($e, $phase);
            Log::error('Ovoko car models sync runner batch failed defensively.', [
                'marker' => self::RECOVERY_MARKER,
                'run_id' => $run->id,
            ] + $diagnostic);

            $this->persistDefensiveFailure($run, $diagnostic, $batch);

            return ['ok' => false, 'marker' => self::MARKER, 'reason' => 'batch_failed_defensively'] + $diagnostic;
        }
    }

    public function status(?OvokoCarModelSyncRun $run = null): array
    {
        $run ??= $this->activeRun() ?? $this->latestRun();
        if (! $run) {
            return $this->emptyStatus();
        }
        $brandsWithModels = OvokoCarDictionaryEntry::query()->where('dictionary', 'models')->distinct('ovoko_brand_id')->count('ovoko_brand_id');
        $brandsTotal = OvokoCarDictionaryEntry::query()->where('dictionary', 'brands')->count();
        return [
            'ok' => true, 'marker' => self::MARKER, 'run_id' => $run->id, 'status' => $run->status, 'batch_size' => $run->batch_size,
            'delay_seconds' => $run->delay_seconds, 'only_missing' => $run->only_missing,
            'total_brand_count' => $run->total_brand_count, 'processed_brand_count' => $run->processed_brand_count,
            'remaining_brand_count' => max(0, (int) $run->total_brand_count - (int) $run->processed_brand_count),
            'brands_with_models' => $brandsWithModels, 'brands_without_models' => max(0, $brandsTotal - $brandsWithModels),
            'synced_models_count' => $run->synced_models_count, 'failed_brand_count' => $run->failed_brand_count,
            'last_batch' => $run->last_batch ?? [], 'errors' => $run->errors ?? [], 'failed_brands' => $run->failed_brands ?? [],
            'started_at' => $run->started_at?->toISOString(), 'updated_at' => $run->updated_at?->toISOString(), 'completed_at' => $run->completed_at?->toISOString(),
        ];
    }

    private function eligibleBrandsQuery(bool $onlyMissing, array $excludeBrandIds = [])
    {
        return OvokoCarDictionaryEntry::query()
            ->where('dictionary', 'brands')
            ->when($excludeBrandIds !== [], fn ($q) => $q->whereNotIn('ovoko_id', $this->normalizeBrandIdList($excludeBrandIds)))
            ->when($onlyMissing, fn ($q) => $q->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')->from('ovoko_car_dictionary_entries as models')
                    ->where('models.dictionary', 'models')
                    ->whereColumn('models.ovoko_brand_id', 'ovoko_car_dictionary_entries.ovoko_id');
            }))
            ->orderBy('ovoko_id');
    }

    private function normalizeBrandId(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return trim((string) $value);
        }

        return '';
    }

    private function normalizeBrandIdList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(fn ($item): string => $this->normalizeBrandId($item), $value), static fn (string $item): bool => $item !== '')));
    }

    private function normalizeListOfArrays(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(fn (array $item): array => $this->normalizeBrandIdFields($item), array_filter($value, static fn ($item): bool => is_array($item))));
    }

    private function normalizeBrandIdFields(array $value): array
    {
        foreach (['brand_id', 'ovoko_id', 'ovoko_brand_id'] as $key) {
            if (array_key_exists($key, $value)) {
                $value[$key] = $this->normalizeBrandId($value[$key]);
            }
        }

        foreach (['brand_ids', 'processed_brand_ids'] as $key) {
            if (array_key_exists($key, $value)) {
                $value[$key] = $this->normalizeBrandIdList($value[$key]);
            }
        }

        return $value;
    }

    private function normalizeBatchBrands(mixed $value): array
    {
        $brands = $this->normalizeListOfArrays($value);

        return array_values(array_map(function (array $brand): array {
            if (array_key_exists('brand_id', $brand)) {
                $brand['brand_id'] = $this->normalizeBrandId($brand['brand_id']);
            }

            return $brand;
        }, $brands));
    }

    private function exceptionDiagnostic(\Throwable $e, string $phase, array $context = []): array
    {
        $message = (string) Str::limit($e->getMessage(), 500);

        return $context + [
            'phase' => $phase,
            'exception_class' => $e::class,
            'exception_message' => $message,
            'error' => $message,
            'failed_at' => now()->toISOString(),
        ];
    }

    private function persistDefensiveFailure(OvokoCarModelSyncRun $run, array $diagnostic, array $batch): void
    {
        try {
            $run->refresh();
            $errors = $this->normalizeListOfArrays($run->errors);
            $errors[] = $diagnostic + ['runner_error' => true];
            $lastBatch = $this->normalizeBatchBrands(data_get($run->last_batch, 'brands'));
            $batch['brands'] = $this->normalizeBatchBrands($batch['brands'] ?: $lastBatch);
            $batch['finished_at'] = now()->toISOString();
            $batch['status'] = 'failed_defensively';
            $batch['error'] = $diagnostic;
            $run->update([
                'status' => in_array($run->status, [OvokoCarModelSyncRun::STATUS_QUEUED, OvokoCarModelSyncRun::STATUS_RUNNING], true) ? OvokoCarModelSyncRun::STATUS_QUEUED : $run->status,
                'errors' => array_slice($errors, -100),
                'last_batch' => $batch,
            ]);
        } catch (\Throwable $persistException) {
            Log::critical('Ovoko car models sync runner could not persist defensive failure.', [
                'marker' => self::RECOVERY_MARKER,
                'run_id' => $run->id,
                'original' => $diagnostic,
                'persist_exception_class' => $persistException::class,
                'persist_exception_message' => $persistException->getMessage(),
            ]);
        }
    }

    private function activeRun(): ?OvokoCarModelSyncRun
    {
        return OvokoCarModelSyncRun::query()
            ->whereIn('status', [OvokoCarModelSyncRun::STATUS_QUEUED, OvokoCarModelSyncRun::STATUS_RUNNING])
            ->latest('id')
            ->first();
    }

    private function latestRun(): ?OvokoCarModelSyncRun
    {
        return OvokoCarModelSyncRun::query()->latest('id')->first();
    }

    private function emptyStatus(): array
    {
        return ['ok' => true, 'marker' => self::MARKER, 'status' => 'idle', 'batch_size' => 5, 'delay_seconds' => 10, 'only_missing' => true, 'total_brand_count' => 0, 'processed_brand_count' => 0, 'remaining_brand_count' => 0, 'brands_with_models' => 0, 'brands_without_models' => 0, 'synced_models_count' => 0, 'failed_brand_count' => 0, 'last_batch' => [], 'errors' => [], 'started_at' => null, 'updated_at' => null, 'completed_at' => null];
    }
}

<?php

namespace App\Services\Marketplace\Ovoko;

use App\Jobs\SyncOvokoCarModelsBatchJob;
use App\Models\OvokoCarDictionaryEntry;
use App\Models\OvokoCarModelSyncRun;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OvokoCarModelSyncRunnerService
{
    public const MARKER = 'ovoko_car_models_sync_runner_v1';
    public const START_CONFIRM = 'start-ovoko-car-models-sync-runner';
    public const STOP_CONFIRM = 'stop-ovoko-car-models-sync-runner';

    public function start(array $input): array
    {
        $batchSize = min(10, max(1, (int) ($input['batch_size'] ?? 5)));
        $delaySeconds = max(5, (int) ($input['delay_seconds'] ?? 10));
        $onlyMissing = filter_var($input['only_missing'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $onlyMissing = $onlyMissing ?? true;

        if (($input['confirm'] ?? null) !== self::START_CONFIRM) {
            return ['ok' => false, 'marker' => self::MARKER, 'blocked' => true, 'reason' => 'missing_confirm_token', 'expected_confirm' => self::START_CONFIRM];
        }

        if (OvokoCarModelSyncRun::query()->whereIn('status', [OvokoCarModelSyncRun::STATUS_RUNNING, OvokoCarModelSyncRun::STATUS_QUEUED])->exists()) {
            return ['ok' => false, 'marker' => self::MARKER, 'blocked' => true, 'reason' => 'active_runner_exists'] + $this->status();
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

        $run = $this->latestRun();
        if ($run && in_array($run->status, [OvokoCarModelSyncRun::STATUS_RUNNING, OvokoCarModelSyncRun::STATUS_QUEUED], true)) {
            $run->update(['status' => OvokoCarModelSyncRun::STATUS_STOPPED, 'completed_at' => now()]);
        }

        return ['ok' => true, 'marker' => self::MARKER] + $this->status($run?->fresh());
    }

    public function runNextBatch(int $runId): array
    {
        $run = OvokoCarModelSyncRun::query()->find($runId);
        if (! $run || ! in_array($run->status, [OvokoCarModelSyncRun::STATUS_QUEUED, OvokoCarModelSyncRun::STATUS_RUNNING], true)) {
            return ['ok' => false, 'marker' => self::MARKER, 'reason' => 'runner_not_active'];
        }

        return DB::transaction(function () use ($run): array {
            $run->refresh();
            if (! in_array($run->status, [OvokoCarModelSyncRun::STATUS_QUEUED, OvokoCarModelSyncRun::STATUS_RUNNING], true)) {
                return ['ok' => true, 'marker' => self::MARKER, 'stopped' => true];
            }
            $run->update(['status' => OvokoCarModelSyncRun::STATUS_RUNNING]);

            $processedIds = array_map('strval', $run->processed_brand_ids ?? []);
            $brands = $this->eligibleBrandsQuery((bool) $run->only_missing, $processedIds)->limit((int) $run->batch_size)->get();
            if ($brands->isEmpty()) {
                $run->update(['status' => OvokoCarModelSyncRun::STATUS_COMPLETED, 'completed_at' => now(), 'last_batch' => ['brand_count' => 0, 'completed' => true, 'finished_at' => now()->toISOString()]]);
                return ['ok' => true, 'marker' => self::MARKER, 'completed' => true];
            }

            /** @var OvokoApiClient $client */
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            $dictionaryService = app(OvokoCarDictionaryService::class);
            $batch = ['started_at' => now()->toISOString(), 'brands' => []];
            $synced = 0;
            $failed = $run->failed_brands ?? [];
            $errors = $run->errors ?? [];

            foreach ($brands as $brand) {
                $brandId = (string) $brand->ovoko_id;
                try {
                    $result = $client->fetchCarDictionary('models', $brandId);
                    if (! ($result['api_ok'] ?? false)) {
                        throw new \RuntimeException((string) ($result['error'] ?? 'Ovoko car models request failed.'));
                    }
                    $count = $dictionaryService->storeRows('models', $result['rows'] ?? [], $brandId);
                    $synced += $count;
                    $batch['brands'][] = ['brand_id' => $brandId, 'brand_name' => $brand->name, 'status' => 'synced', 'models_count' => $count];
                } catch (\Throwable $e) {
                    $error = Str::limit($e->getMessage(), 500)->toString();
                    $failed[] = ['brand_id' => $brandId, 'brand_name' => $brand->name, 'error' => $error];
                    $errors[] = ['brand_id' => $brandId, 'brand_name' => $brand->name, 'error' => $error];
                    $batch['brands'][] = ['brand_id' => $brandId, 'brand_name' => $brand->name, 'status' => 'failed', 'error' => $error];
                }
                $processedIds[] = $brandId;
            }

            $remaining = $this->eligibleBrandsQuery((bool) $run->only_missing, $processedIds)->count();
            $batch['finished_at'] = now()->toISOString();
            $batch['remaining_brand_count'] = $remaining;
            $run->update([
                'status' => $remaining > 0 ? OvokoCarModelSyncRun::STATUS_QUEUED : OvokoCarModelSyncRun::STATUS_COMPLETED,
                'processed_brand_count' => count(array_unique($processedIds)),
                'synced_models_count' => ((int) $run->synced_models_count) + $synced,
                'failed_brand_count' => count($failed),
                'last_offset' => count(array_unique($processedIds)),
                'processed_brand_ids' => array_values(array_unique($processedIds)),
                'failed_brands' => $failed,
                'errors' => array_slice($errors, -100),
                'last_batch' => $batch,
                'completed_at' => $remaining > 0 ? null : now(),
            ]);

            if ($remaining > 0) {
                SyncOvokoCarModelsBatchJob::dispatch((int) $run->id)->delay(now()->addSeconds((int) $run->delay_seconds));
            }

            return ['ok' => true, 'marker' => self::MARKER, 'remaining_brand_count' => $remaining];
        });
    }

    public function status(?OvokoCarModelSyncRun $run = null): array
    {
        $run ??= $this->latestRun();
        if (! $run) {
            return $this->emptyStatus();
        }
        $brandsWithModels = OvokoCarDictionaryEntry::query()->where('dictionary', 'models')->distinct('ovoko_brand_id')->count('ovoko_brand_id');
        $brandsTotal = OvokoCarDictionaryEntry::query()->where('dictionary', 'brands')->count();
        return [
            'ok' => true, 'marker' => self::MARKER, 'status' => $run->status, 'batch_size' => $run->batch_size,
            'delay_seconds' => $run->delay_seconds, 'only_missing' => $run->only_missing,
            'total_brand_count' => $run->total_brand_count, 'processed_brand_count' => $run->processed_brand_count,
            'remaining_brand_count' => max(0, (int) $run->total_brand_count - (int) $run->processed_brand_count),
            'brands_with_models' => $brandsWithModels, 'brands_without_models' => max(0, $brandsTotal - $brandsWithModels),
            'synced_models_count' => $run->synced_models_count, 'failed_brand_count' => $run->failed_brand_count,
            'last_batch' => $run->last_batch ?? [], 'errors' => $run->errors ?? [],
            'started_at' => $run->started_at?->toISOString(), 'updated_at' => $run->updated_at?->toISOString(), 'completed_at' => $run->completed_at?->toISOString(),
        ];
    }

    private function eligibleBrandsQuery(bool $onlyMissing, array $excludeBrandIds = [])
    {
        return OvokoCarDictionaryEntry::query()
            ->where('dictionary', 'brands')
            ->when($excludeBrandIds !== [], fn ($q) => $q->whereNotIn('ovoko_id', $excludeBrandIds))
            ->when($onlyMissing, fn ($q) => $q->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')->from('ovoko_car_dictionary_entries as models')
                    ->where('models.dictionary', 'models')
                    ->whereColumn('models.ovoko_brand_id', 'ovoko_car_dictionary_entries.ovoko_id');
            }))
            ->orderBy('ovoko_id');
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

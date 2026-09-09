<?php

namespace App\Services\JarekGearboxes;

use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class JarekGearboxEbayPriceApplyRunnerService
{
    private const KEY = 'jarek:ebay-de:price-apply-runner';

    public function start(string $snapshotId, int $batchSize, int $delayMs): array
    {
        $preview = app(JarekGearboxEbayBulkPricePreviewService::class)->preview(7, 'ebay_de');
        if (! hash_equals($preview['snapshot_id'], $snapshotId)) return ['ok' => false, 'error' => 'preview_snapshot_changed'];
        $state = ['ok' => true, 'apply_run_id' => (string) Str::uuid(), 'snapshot_id' => $snapshotId, 'status' => 'running', 'current_offset' => 0, 'processed_count' => 0, 'success_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => count($preview['eligible_products']), 'eligible_count' => count($preview['eligible_products']), 'batch_size' => min(10, max(1, $batchSize)), 'delay_ms' => min(30000, max(1000, $delayMs)), 'last_success' => null, 'last_error' => null, 'batch_history' => [], 'started_at' => now()->toIso8601String()];
        return $this->save($state);
    }

    public function control(string $action): array
    {
        $state = $this->state();
        if (! $state) return ['ok' => false, 'error' => 'no_apply_run'];
        if ($action === 'pause' && $state['status'] === 'running') $state['status'] = 'paused';
        elseif ($action === 'resume' && $state['status'] === 'paused') $state['status'] = 'running';
        elseif ($action === 'stop' && in_array($state['status'], ['running', 'paused'], true)) $state['status'] = 'stopped';
        else return ['ok' => false, 'error' => 'invalid_state_transition'] + $state;
        return $this->save($state);
    }

    public function batch(JarekGearboxEbayPriceApplyService $apply): array
    {
        return Cache::lock(self::KEY.':lock', 60)->block(1, function () use ($apply): array {
            $state = $this->state();
            if (! $state || $state['status'] !== 'running') return ['ok' => false, 'error' => 'runner_is_not_running'] + ($state ?? []);
            return $this->applyBatch($state, $apply);
        });
    }

    public function resumeBatch(string $snapshotId, JarekGearboxEbayPriceApplyService $apply): array
    {
        return Cache::lock(self::KEY.':lock', 60)->block(1, function () use ($snapshotId, $apply): array {
            $state = $this->state();
            if (! $state) return ['ok' => false, 'marketplace_write' => false, 'error' => 'no_apply_run'];
            if (! hash_equals((string) $state['snapshot_id'], $snapshotId)) return ['ok' => false, 'marketplace_write' => false, 'error' => 'snapshot_id_does_not_match_runner'] + $state;
            if (! in_array($state['status'], ['running', 'paused'], true)) return ['ok' => false, 'marketplace_write' => false, 'error' => 'runner_cannot_be_resumed'] + $state;
            $state['status'] = 'running';

            return $this->applyBatch($state, $apply);
        });
    }

    private function applyBatch(array $state, JarekGearboxEbayPriceApplyService $apply): array
    {
        $result = $apply->apply($state['snapshot_id'], $state['batch_size'], $state['current_offset'], [], $state['apply_run_id']);
        $items = collect($result['results'] ?? []);
        $count = $items->count();
        $state['current_offset'] += $count;
        $state['processed_count'] += $count;
        $state['success_count'] += $items->whereIn('status', ['success', 'already_updated'])->count();
        $state['failed_count'] += $items->where('status', 'failed')->count();
        $state['skipped_count'] += $items->where('status', 'skipped')->count();
        $state['remaining_count'] = max(0, $state['eligible_count'] - $state['current_offset']);
        $success = $items->whereIn('status', ['success', 'already_updated'])->last();
        $failure = $items->whereIn('status', ['failed', 'skipped'])->last();
        if ($success) $state['last_success'] = $success;
        if ($failure) $state['last_error'] = $failure;
        $state['batch_history'][] = ['apply_batch_id' => $result['apply_batch_id'] ?? null, 'offset' => $state['current_offset'] - $count, 'count' => $count, 'success' => $items->whereIn('status', ['success', 'already_updated'])->count(), 'failed' => $items->where('status', 'failed')->count(), 'skipped' => $items->where('status', 'skipped')->count(), 'at' => now()->toIso8601String()];
        $state['batch_history'] = array_slice($state['batch_history'], -100);
        if ($result['stop_reason'] ?? null) { $state['status'] = 'stopped_on_error'; $state['last_error'] = ['error' => $result['stop_reason']]; }
        elseif ($state['remaining_count'] === 0 || $count === 0) $state['status'] = 'completed';
        return $this->save($state) + ['batch' => $result];
    }

    public function status(?string $snapshotId = null): array
    {
        $state = $this->state();
        $snapshotId ??= $state['snapshot_id'] ?? null;
        $updated = $snapshotId ? MarketplaceSyncLog::query()->where('action', 'jarek_gearboxes_ebay_bulk_price_increase_apply')->where('status', 'success')->where('payload', 'like', '%"snapshot_id":"'.$snapshotId.'"%')->count() : 0;
        return ['ok' => true, 'read_only' => true, 'marketplace_write' => false, 'last_apply_run_id' => $state['apply_run_id'] ?? null, 'snapshot_id' => $snapshotId, 'success_count' => $state['success_count'] ?? 0, 'failed_count' => $state['failed_count'] ?? 0, 'skipped_count' => $state['skipped_count'] ?? 0, 'has_already_updated_records' => $updated > 0, 'already_updated_count' => $updated, 'runner' => $state];
    }

    private function state(): ?array { return Cache::get(self::KEY); }
    private function save(array $state): array { $state['updated_at'] = now()->toIso8601String(); Cache::forever(self::KEY, $state); return $state; }
}

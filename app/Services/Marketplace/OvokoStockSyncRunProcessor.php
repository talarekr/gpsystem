<?php

namespace App\Services\Marketplace;

use App\Http\Controllers\Tools\OvokoStockSyncController;
use App\Models\OvokoStockSyncRun;
use App\Models\Part;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class OvokoStockSyncRunProcessor
{
    public function __construct(private readonly OvokoStockSyncController $planner) {}

    public function tick(OvokoStockSyncRun $run): array
    {
        try {
            $lock = Cache::lock('ovoko-stock-sync-run:'.$run->id, 120);

            if (! $lock->get()) {
                return ['ok' => false, 'locked' => true, 'cache_lock_available' => true, 'message' => 'Run is already being processed by another tick.'] + $run->fresh()->summary();
            }

            try {
                return ['ok' => true, 'locked' => false, 'cache_lock_available' => true] + $this->processUnlocked($run->fresh());
            } finally {
                optional($lock)->release();
            }
        } catch (Throwable $e) {
            return ['ok' => true, 'locked' => false, 'cache_lock_available' => false, 'lock_warning' => $e->getMessage()] + $this->processUnlocked($run->fresh());
        }
    }

    private function processUnlocked(?OvokoStockSyncRun $run): array
    {
        if (! $run) {
            return ['status' => 'missing', 'marketplace_write' => false];
        }

        if (! in_array($run->status, ['queued', 'running'], true)) {
            return $run->summary();
        }

        if ($run->cancel_requested_at) {
            return $this->cancel($run)->summary();
        }

        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();

        try {
            $parts = Part::query()
                ->where('needs_listing', false)
                ->where('id', '>', (int) ($run->last_processed_part_id ?? 0))
                ->orderBy('id')
                ->limit(OvokoStockSyncRun::BATCH_SIZE)
                ->get(['id']);

            if ($parts->isEmpty()) {
                $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();

                return $run->fresh()->summary();
            }

            $blockers = $run->top_blockers ?? [];
            $recent = $run->recent_results ?? [];

            foreach ($parts as $partRow) {
                $run = $run->fresh();

                if ($run->cancel_requested_at) {
                    return $this->cancel($run)->summary();
                }

                $item = $this->planner->planPart((int) $partRow->id);
                $action = $item['action'] ?? 'failed';

                if (in_array($action, ['should_mark_for_sale', 'should_mark_sold'], true) && $run->mode === 'apply' && ($item['blockers'] ?? []) === []) {
                    $applied = $this->applyItem($item);
                    $action = $applied ? 'applied' : 'blocked';
                    $this->planner->writeLog($item, $applied, $applied ? 'success' : 'blocked', $applied ? [] : ['part_unavailable_or_guard_failed_during_apply'], (int) $run->id);
                }

                foreach (($item['blockers'] ?? []) as $blocker) {
                    $blockers[$blocker] = ($blockers[$blocker] ?? 0) + 1;
                }

                $recent[] = $this->compactItem($item, $action);
                $recent = array_slice($recent, -20);

                $run->forceFill($this->availabilityCounterUpdates($run, $item) + $this->counterUpdates($run, $action) + [
                    'processed_count' => (int) $run->processed_count + 1,
                    'last_processed_part_id' => (int) $partRow->id,
                    'top_blockers' => $this->topBlockers($blockers),
                    'recent_results' => $recent,
                ])->save();
            }

            $run = $run->fresh();
            if ((int) $run->processed_count >= (int) $run->total_candidates) {
                $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();
            }

            return $run->fresh()->summary();
        } catch (Throwable $e) {
            $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'last_error' => $e->getMessage()])->save();

            return $run->fresh()->summary();
        }
    }

    private function applyItem(array $item): bool
    {
        return (bool) DB::transaction(function () use ($item): bool {
            $part = Part::query()->lockForUpdate()->find($item['part_id']);
            if (! $part || (bool) $part->needs_listing) return false;
            $part->forceFill(array_intersect_key($item['planned_local_state'] ?? [], array_flip(['quantity', 'status', 'is_visible_storefront'])))->save();

            return true;
        });
    }

    private function availabilityCounterUpdates(OvokoStockSyncRun $run, array $item): array
    {
        $updates = [];
        $available = $item['available_on_ovoko'] ?? null;
        if ($available === true) $updates['available_on_ovoko_count'] = (int) $run->available_on_ovoko_count + 1;
        elseif ($available === false) $updates['not_available_on_ovoko_count'] = (int) $run->not_available_on_ovoko_count + 1;
        else $updates['availability_unknown_count'] = (int) $run->availability_unknown_count + 1;

        $local = $item['local_availability'] ?? data_get($item, 'local.availability');
        if ($local === 'for_sale') $updates['local_for_sale_count'] = (int) $run->local_for_sale_count + 1;
        elseif ($local === 'sold') $updates['local_sold_count'] = (int) $run->local_sold_count + 1;

        return $updates;
    }

    private function counterUpdates(OvokoStockSyncRun $run, string $action): array
    {
        return match ($action) {
            'already_correct' => ['no_change_count' => (int) $run->no_change_count + 1, 'already_correct_count' => (int) $run->already_correct_count + 1],
            'should_mark_for_sale' => ['would_update_count' => (int) $run->would_update_count + 1, 'should_mark_for_sale_count' => (int) $run->should_mark_for_sale_count + 1],
            'should_mark_sold' => ['would_update_count' => (int) $run->would_update_count + 1, 'should_mark_sold_count' => (int) $run->should_mark_sold_count + 1],
            'applied' => ['applied_count' => (int) $run->applied_count + 1],
            'skipped' => ['skipped_count' => (int) $run->skipped_count + 1],
            'blocked' => ['blocked_count' => (int) $run->blocked_count + 1],
            default => ['failed_count' => (int) $run->failed_count + 1],
        };
    }

    private function compactItem(array $item, string $action): array
    {
        return ['part_id' => $item['part_id'] ?? null, 'ovoko_id' => $item['ovoko_id'] ?? null, 'mapping_source' => $item['ovoko_mapping_source'] ?? null, 'available_on_ovoko' => $item['available_on_ovoko'] ?? null, 'ovoko_availability_source' => $item['ovoko_availability_source'] ?? null, 'ovoko_status_raw' => $item['ovoko_status_raw'] ?? null, 'ovoko_status_meaning' => $item['ovoko_status_meaning'] ?? null, 'reserved_user' => $item['reserved_user'] ?? null, 'reserved_date' => $item['reserved_date'] ?? null, 'local_availability' => $item['local_availability'] ?? data_get($item, 'local.availability'), 'recommended_local_availability' => $item['recommended_local_availability'] ?? null, 'local_before' => $item['local'] ?? null, 'ovoko_stock_status' => $item['ovoko'] ?? null, 'planned_or_applied_local_state' => $item['planned_local_state'] ?? null, 'action' => $action, 'blockers' => $item['blockers'] ?? [], 'error' => $item['error'] ?? null];
    }

    private function topBlockers(array $blockers): array
    {
        arsort($blockers);

        return array_slice($blockers, 0, 10, true);
    }

    private function cancel(OvokoStockSyncRun $run): OvokoStockSyncRun
    {
        $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();

        return $run->fresh();
    }
}

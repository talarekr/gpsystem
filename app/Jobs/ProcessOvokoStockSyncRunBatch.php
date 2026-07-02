<?php

namespace App\Jobs;

use App\Http\Controllers\Tools\OvokoStockSyncController;
use App\Models\OvokoStockSyncRun;
use App\Models\Part;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessOvokoStockSyncRunBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $runId) {}

    public function handle(OvokoStockSyncController $planner): void
    {
        $run = OvokoStockSyncRun::query()->find($this->runId);
        if (! $run || ! in_array($run->status, ['queued', 'running'], true)) return;
        if ($run->cancel_requested_at) { $this->cancel($run); return; }
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        try {
            $parts = Part::query()->where('needs_listing', false)->where('id', '>', (int) ($run->last_processed_part_id ?? 0))->orderBy('id')->limit(OvokoStockSyncRun::BATCH_SIZE)->get(['id']);
            if ($parts->isEmpty()) { $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save(); return; }
            $blockers = $run->top_blockers ?? []; $recent = $run->recent_results ?? [];
            foreach ($parts as $partRow) {
                $run = $run->fresh();
                if ($run->cancel_requested_at) { $this->cancel($run); return; }
                $item = $planner->planPart((int) $partRow->id); $action = $item['action'] ?? 'failed';
                if ($action === 'update_local_stock' && $run->mode === 'apply' && ($item['blockers'] ?? []) === []) {
                    $applied = $this->applyItem($item); $action = $applied ? 'applied' : 'blocked';
                    $planner->writeLog($item, $applied, $applied ? 'success' : 'blocked', $applied ? [] : ['part_unavailable_or_guard_failed_during_apply'], (int) $run->id);
                }
                foreach (($item['blockers'] ?? []) as $blocker) $blockers[$blocker] = ($blockers[$blocker] ?? 0) + 1;
                $recent[] = $this->compactItem($item, $action); $recent = array_slice($recent, -20);
                $run->forceFill($this->counterUpdates($run, $action) + ['processed_count' => (int) $run->processed_count + 1, 'last_processed_part_id' => (int) $partRow->id, 'top_blockers' => $this->topBlockers($blockers), 'recent_results' => $recent])->save();
            }
            $run = $run->fresh();
            if ((int) $run->processed_count >= (int) $run->total_candidates) { $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save(); return; }
            self::dispatch((int) $run->id);
        } catch (Throwable $e) { $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'last_error' => $e->getMessage()])->save(); }
    }

    private function applyItem(array $item): bool
    { return (bool) DB::transaction(function () use ($item): bool { $part = Part::query()->lockForUpdate()->find($item['part_id']); if (! $part || (bool) $part->needs_listing || $part->status === 'sold') return false; $part->forceFill(array_intersect_key($item['planned_local_state'] ?? [], array_flip(['quantity', 'status', 'is_visible_storefront'])))->save(); return true; }); }

    private function counterUpdates(OvokoStockSyncRun $run, string $action): array
    { return match ($action) { 'no_change' => ['no_change_count' => (int) $run->no_change_count + 1], 'update_local_stock' => ['would_update_count' => (int) $run->would_update_count + 1], 'applied' => ['applied_count' => (int) $run->applied_count + 1], 'skipped' => ['skipped_count' => (int) $run->skipped_count + 1], 'blocked' => ['blocked_count' => (int) $run->blocked_count + 1], default => ['failed_count' => (int) $run->failed_count + 1], }; }

    private function compactItem(array $item, string $action): array
    { return ['part_id' => $item['part_id'] ?? null, 'ovoko_id' => $item['ovoko_id'] ?? null, 'mapping_source' => $item['ovoko_mapping_source'] ?? null, 'local_before' => $item['local'] ?? null, 'ovoko_stock_status' => $item['ovoko'] ?? null, 'planned_or_applied_local_state' => $item['planned_local_state'] ?? null, 'action' => $action, 'blockers' => $item['blockers'] ?? [], 'error' => $item['error'] ?? null]; }

    private function topBlockers(array $blockers): array
    { arsort($blockers); return array_slice($blockers, 0, 10, true); }

    private function cancel(OvokoStockSyncRun $run): void
    { $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save(); }
}

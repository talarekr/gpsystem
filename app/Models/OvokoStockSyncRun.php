<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OvokoStockSyncRun extends Model
{
    public const BATCH_SIZE = 20;

    protected $fillable = ['mode','status','batch_size','total_candidates','processed_count','last_processed_part_id','no_change_count','would_update_count','applied_count','blocked_count','skipped_count','failed_count','top_blockers','recent_results','started_at','finished_at','last_error','cancel_requested_at'];

    protected function casts(): array
    {
        return ['top_blockers' => 'array', 'recent_results' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'cancel_requested_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OvokoStockSyncRunItem::class);
    }

    public function remainingCount(): int
    {
        return max(0, (int) $this->total_candidates - (int) $this->processed_count);
    }

    public function progressPercent(): float
    {
        if ((int) $this->total_candidates <= 0) return in_array($this->status, ['completed', 'cancelled'], true) ? 100.0 : 0.0;

        return round(((int) $this->processed_count / (int) $this->total_candidates) * 100, 2);
    }

    public function summary(): array
    {
        return [
            'run_id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->status,
            'batch_size' => (int) $this->batch_size,
            'total_candidates' => (int) $this->total_candidates,
            'processed_count' => (int) $this->processed_count,
            'remaining_count' => $this->remainingCount(),
            'progress_percent' => $this->progressPercent(),
            'no_change_count' => (int) $this->no_change_count,
            'would_update_count' => (int) $this->would_update_count,
            'applied_count' => (int) $this->applied_count,
            'blocked_count' => (int) $this->blocked_count,
            'skipped_count' => (int) $this->skipped_count,
            'failed_count' => (int) $this->failed_count,
            'top_blockers' => $this->top_blockers ?? [],
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'last_processed_part_id' => $this->last_processed_part_id ? (int) $this->last_processed_part_id : null,
            'last_error' => $this->last_error,
            'marketplace_write' => false,
            'has_more' => in_array($this->status, ['queued', 'running'], true),
            'recent_results' => $this->recent_results ?? [],
            'warnings' => ['local_stock_only_no_price_no_publish_no_relist_no_end_no_marketplace_writes'],
        ];
    }
}

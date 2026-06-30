<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EbayListingAuditRunnerService
{
    private const CACHE_PREFIX = 'ebay_listing_audit_runner:';
    private const MAX_BATCH_SIZE = 20;
    private const PROBLEM_LIMIT = 500;

    public function __construct(private EbayListingAuditService $audit) {}

    public function startOrContinue(string $channel, int $batchSize = 20, ?string $runId = null, bool $start = false, bool $cancel = false, bool $confirmedCancel = false): array
    {
        abort_unless(in_array($channel, ['ebay_de', 'ebay_fr', 'ebay'], true), 422, 'Supported eBay channel values: ebay_de, ebay_fr, ebay.');
        $batchSize = max(1, min(self::MAX_BATCH_SIZE, $batchSize));

        if ($cancel) {
            $run = $this->load($runId ?: '');
            abort_unless($run && $confirmedCancel, 422, 'Cancel requires existing run_id and confirm=cancel-ebay-audit-runner.');
            $run['status'] = 'failed';
            $run['cancelled'] = true;
            $run['finished_at'] = now()->toISOString();
            $this->save($run);
            return $this->response($run, $batchSize, (int) $run['offset'], (int) $run['offset'], 0);
        }

        $run = $runId ? $this->load($runId) : null;
        if (! $run || $start) {
            $run = $this->newRun($channel);
        }

        if (($run['status'] ?? null) === 'completed') {
            return $this->response($run, $batchSize, (int) $run['offset'], (int) $run['offset'], 0);
        }

        $offsetBefore = (int) ($run['offset'] ?? 0);
        $result = $this->audit->run(channel: $channel, limit: $batchSize, offset: $offsetBefore, partId: null, apply: false, checkApi: true);
        $rows = $result['results'] ?? [];
        $processed = count($rows);
        $offsetAfter = $offsetBefore + $processed;

        $run['offset'] = $offsetAfter;
        $run['processed_count'] = $offsetAfter;
        $run['summary'] = $this->mergeSummary($run['summary'] ?? $this->emptySummary($channel), $rows, $processed);
        $run['problem_samples'] = $this->mergeProblems($run['problem_samples'] ?? [], $rows);
        $run['status'] = $offsetAfter >= (int) $run['total_count'] || $processed === 0 ? 'completed' : 'running';
        if ($run['status'] === 'completed') $run['finished_at'] = now()->toISOString();
        $this->save($run);

        return $this->response($run, $batchSize, $offsetBefore, $offsetAfter, $processed, $this->batchSummary($rows, $processed));
    }

    public function status(string $runId): ?array
    {
        $run = $this->load($runId);
        return $run ? $this->response($run, (int) ($run['batch_size'] ?? 20), (int) $run['offset'], (int) $run['offset'], 0) : null;
    }

    private function newRun(string $channel): array
    {
        $total = Schema::hasTable('marketplace_listings') ? MarketplaceListing::query()->where('marketplace', $channel)->count() : 0;
        $run = ['run_id' => (string) Str::uuid(), 'channel' => $channel, 'total_count' => $total, 'processed_count' => 0, 'offset' => 0, 'started_at' => now()->toISOString(), 'finished_at' => null, 'status' => 'running', 'dry_run' => true, 'marketplace_write' => false, 'publish' => false, 'revise' => false, 'relist' => false, 'end' => false, 'stock_order_price_sync' => false, 'summary' => $this->emptySummary($channel) + ['total_eBay_de_listings' => $total], 'problem_samples' => []];
        $this->save($run);
        return $run;
    }

    private function emptySummary(string $channel): array
    {
        return ['total_eBay_de_listings' => 0, 'processed_count' => 0, 'confirmed_active_listings' => 0, 'confirmed_ended_listings' => 0, 'api_inactive' => 0, 'api_not_found' => 0, 'needs_manual_review' => 0, 'gpsw_external_id_count' => 0, 'suspected_stale_ended_listings' => 0, 'candidates_for_local_fix_high_confidence' => 0, 'errors_count' => 0];
    }

    private function mergeSummary(array $summary, array $rows, int $processed): array
    {
        $c = collect($rows);
        $summary['processed_count'] = (int) ($summary['processed_count'] ?? 0) + $processed;
        $summary['confirmed_active_listings'] += $c->where('final_panel_status', 'active')->count();
        $summary['confirmed_ended_listings'] += $c->where('final_panel_status', 'ended')->count();
        $summary['api_inactive'] += $c->whereIn('api_listing_status', ['inactive', 'ended'])->count();
        $summary['api_not_found'] += $c->whereIn('api_listing_status', ['not_found', 'unavailable'])->count();
        $summary['needs_manual_review'] += $c->whereIn('action', ['needs_manual_review', 'api_needs_verification', 'invalid_external_id'])->count();
        $summary['gpsw_external_id_count'] += $c->filter(fn($r) => preg_match('/^GPSW-\d+$/i', (string)($r['external_offer_id'] ?? '')) || preg_match('/^GPSW-\d+$/i', (string)($r['external_listing_id'] ?? '')))->count();
        $summary['suspected_stale_ended_listings'] += $c->where('action', 'stale_ended_listing')->count();
        $summary['candidates_for_local_fix_high_confidence'] += $c->where('action', 'would_update_url')->where('confidence', 'high')->count();
        $summary['errors_count'] += $c->filter(fn($r) => filled(data_get($r, 'api_check.error_message_safe')))->count();
        return $summary;
    }

    private function batchSummary(array $rows, int $processed): array { return $this->mergeSummary($this->emptySummary('ebay_de'), $rows, $processed); }

    private function mergeProblems(array $existing, array $rows): array
    {
        foreach ($rows as $row) {
            if (($row['should_panel_show_active'] ?? false) && ($row['action'] ?? 'ok_active') === 'ok_active') continue;
            $existing[] = collect($row)->only(['local_part_id','marketplace_listing_id','sku','old_item_id','old_url','api_listing_status','final_panel_status','action'])->all();
            if (count($existing) >= self::PROBLEM_LIMIT) break;
        }
        return array_slice($existing, 0, self::PROBLEM_LIMIT);
    }

    private function response(array $run, int $batchSize, int $offsetBefore, int $offsetAfter, int $processed, ?array $batchSummary = null): array
    {
        $completed = ($run['status'] ?? null) === 'completed';
        $base = '/admin/tools/marketplace/ebay-listing-audit-runner';
        return ['run_id' => $run['run_id'], 'status' => $run['status'], 'batch_size' => $batchSize, 'offset_before' => $offsetBefore, 'offset_after' => $offsetAfter, 'processed_in_batch' => $processed, 'total_count' => $run['total_count'], 'remaining_count' => max(0, (int)$run['total_count'] - (int)$run['offset']), 'completed' => $completed, 'next_url' => $completed ? null : $base.'?channel='.$run['channel'].'&batch_size='.$batchSize.'&run_id='.$run['run_id'], 'summary_total' => $run['summary'], 'summary_batch' => $batchSummary ?? $this->emptySummary($run['channel']), 'problem_samples' => $run['problem_samples'], 'status_url' => $base.'/status?run_id='.$run['run_id'], 'problems_url' => $base.'/status?run_id='.$run['run_id'].'&problems=1'];
    }

    private function load(string $runId): ?array { return $runId !== '' ? Cache::get(self::CACHE_PREFIX.$runId) : null; }
    private function save(array $run): void { Cache::put(self::CACHE_PREFIX.$run['run_id'], $run, now()->addDays(14)); }
}

<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\EbayApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class EbayListingStatusBatchRunnerService
{
    public const MARKER = 'ebay_listing_status_batch_runner_v1';
    public const DRY_RUN_MARKER = 'ebay_listing_status_batch_dry_run_v1';
    public const BROWSER_AUTORUN_MARKER = 'ebay_listing_status_batch_runner_browser_autorun_v2';
    public const STATE_INITIALIZATION_FIX_MARKER = 'ebay_listing_status_batch_runner_state_initialization_fix_v3';
    public const DELAY_COUNTDOWN_FIX_MARKER = 'ebay_listing_status_batch_runner_delay_countdown_fix_v4';
    public const ENDED_PRODUCT_IDS_MARKER = 'ebay_listing_status_ended_product_ids_v1';
    public const ENDED_PRODUCTS_EXPORT_MARKER = 'ebay_listing_status_ended_products_export_v1';
    public const CACHE_KEY = 'admin_tools:ebay_listing_status_sync:v1';

    public function __construct(private readonly EbayListingStatusNormalizer $normalizer) {}

    public function start(array $input): array
    {
        if (! app(EbayConnectionGate::class)->isEbayEnabled()) return $this->disabled('listing_status_sync_start');
        if (($input['confirm'] ?? null) !== 'start-ebay-listing-status-sync') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        if (($input['scope'] ?? 'products_with_ebay_item_id') !== 'products_with_ebay_item_id') return ['ok' => false, 'reason' => 'invalid_scope'];
        if (! filter_var($input['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN)) return ['ok' => false, 'reason' => 'live_mode_disabled'];
        $batch = max(1, min((int) ($input['batch_size'] ?? 10), 20));
        $delay = max(5, (int) ($input['delay_seconds'] ?? 5));
        $ids = $this->eligibleListingIds();
        $state = array_merge($this->baseState('running'), [
            'dry_run' => true,
            'batch_size' => $batch,
            'delay_seconds' => $delay,
            'total' => count($ids),
            'processed' => 0,
            'remaining' => count($ids),
            'remaining_ids' => $ids,
            'processed_ids' => [],
            'started_at' => now()->toISOString(),
            'finished_at' => null,
            'last_batch_at' => null,
        ]);
        Cache::put(self::CACHE_KEY, $state, now()->addHours(12));
        return array_merge($this->publicStatus($state), ['ok' => true, 'completed' => false]);
    }

    public function stop(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'stop-ebay-listing-status-sync') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $state = $this->state();
        if ($state['status'] === 'running') $state['status'] = 'stopped';
        $state['finished_at'] = $state['finished_at'] ?? now()->toISOString();
        Cache::put(self::CACHE_KEY, $state, now()->addHours(12));
        return array_merge($this->publicStatus($state), ['ok' => true]);
    }

    public function runNextBatch(array $input): array
    {
        if (! app(EbayConnectionGate::class)->isEbayEnabled()) return $this->disabled('listing_status_sync_batch');
        if (($input['confirm'] ?? null) !== 'run-next-ebay-listing-status-sync-batch') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $state = $this->state();
        if ($state['status'] !== 'running') return array_merge($this->publicStatus($state), ['ok' => false, 'reason' => 'not_running', 'batch_executed' => false, 'completed' => $state['status'] === 'completed' && empty($state['remaining_ids']) && (int) $state['remaining'] === 0]);
        if (empty($state['remaining_ids'])) return array_merge($this->complete($state), ['ok' => true, 'batch_executed' => false, 'completed' => true]);
        $delay = $this->delayDiagnostics($state);
        if ($delay['retry_after_seconds'] > 0) return array_merge($this->publicStatus($state), ['ok' => true, 'reason' => 'delay_not_elapsed', 'batch_executed' => false, 'should_wait' => true, 'retry_after_seconds' => $delay['retry_after_seconds'], 'next_batch_allowed_at' => $delay['next_batch_allowed_at'], 'clock_skew_detected' => $delay['clock_skew_detected'], 'completed' => false]);

        $batchIds = array_slice($state['remaining_ids'], 0, (int) $state['batch_size']);
        foreach (MarketplaceListing::query()->with('part')->whereIn('id', $batchIds)->orderBy('id')->get() as $listing) {
            $result = $this->checkListing($listing);
            $state = $this->recordResult($state, $result);
        }
        $state['remaining_ids'] = array_values(array_diff($state['remaining_ids'], $batchIds));
        $state['processed_ids'] = array_values(array_unique(array_merge($state['processed_ids'], $batchIds)));
        $state['processed'] = count($state['processed_ids']);
        $state['remaining'] = count($state['remaining_ids']);
        $state['last_batch_at'] = now()->toISOString();
        if (empty($state['remaining_ids']) && $state['processed'] === $state['total']) $state = $this->complete($state, false);
        Cache::put(self::CACHE_KEY, $state, now()->addHours(12));
        return array_merge($this->publicStatus($state), ['ok' => true, 'batch_executed' => true, 'completed' => $state['status'] === 'completed', 'should_wait' => $state['status'] === 'running', 'retry_after_seconds' => $state['status'] === 'running' ? (int) $state['delay_seconds'] : 0, 'next_batch_allowed_at' => $state['status'] === 'running' ? CarbonImmutable::parse($state['last_batch_at'])->addSeconds((int) $state['delay_seconds'])->toISOString() : null, 'clock_skew_detected' => false]);
    }

    public function status(): array { return array_merge($this->publicStatus($this->state()), ['ok' => true]); }

    private function disabled(string $action): array
    {
        try { app(EbayConnectionGate::class)->assertEbayEnabledForSync($action); } catch (\App\Exceptions\EbayConnectionDisabledException) {}
        return ['ok' => false, 'reason' => 'ebay_connection_disabled', 'message' => EbayConnectionGate::BLOCKER, 'marketplace_write' => false, 'batch_executed' => false];
    }

    private function eligibleListingIds(): array
    {
        return MarketplaceListing::query()->whereIn('marketplace', ['ebay', 'ebay_de'])->where(function ($q) {
            $q->whereNotNull('external_listing_id')->orWhereNotNull('external_offer_id')->orWhere('url', 'like', '%/itm/%')->orWhereNotNull('raw_payload');
        })->orderBy('id')->get()->filter(fn ($listing): bool => filled($this->itemId($listing)))->pluck('id')->values()->all();
    }

    private function checkListing(MarketplaceListing $listing): array
    {
        $itemId = $this->itemId($listing);
        $api = [];
        try {
            $channel = in_array($listing->marketplace, ['ebay_de'], true) ? $listing->marketplace : 'ebay_de';
            $account = MarketplaceAccount::query()->where('code', $channel)->first();
            $api = (new EbayApiClient($channel, $account))->getListingStatusByItemId((string) $itemId);
        } catch (\Throwable $e) {
            $api = ['http_status' => null, 'api_listing_status' => 'unknown', 'error_type' => 'transient_error', 'error' => $e->getMessage()];
        }
        $normalized = $this->normalizer->normalize($api);
        $errorType = $this->errorType($api, $normalized);
        return [
            'part_id' => $listing->part_id, 'marketplace_listing_id' => $listing->id, 'ebay_item_id' => $itemId,
            'local_status' => $listing->status, 'normalized_status' => $normalized['normalized_status'],
            'currently_blocks_relisting' => $this->blocksRelisting($listing), 'should_allow_relisting' => $normalized['should_allow_relisting'],
            'http_status' => $api['http_status'] ?? null, 'error_type' => $errorType,
        ];
    }

    private function itemId(?MarketplaceListing $listing): ?string
    {
        foreach ([$listing?->external_listing_id, $listing?->external_offer_id] as $value) if (is_string($value) && preg_match('/\d{6,}/', $value, $m)) return $m[0];
        if ($listing && preg_match('#/itm/(\d+)#', (string) $listing->url, $m)) return $m[1];
        foreach (['itemId','item_id','ebay_item_id'] as $key) if ($listing && preg_match('/\d{6,}/', (string) Arr::get($listing->raw_payload, $key), $m)) return $m[0];
        return null;
    }

    private function blocksRelisting(MarketplaceListing $l): bool { return in_array(strtolower((string) $l->status), ['active','published','live'], true) && ! in_array(strtolower((string) $l->last_api_status), ['ended','failed','deleted','archived','not_found','inactive','unavailable','error'], true) && $l->not_seen_in_active_api_at === null; }
    private function errorType(array $api, array $n): ?string { $h=(int)($api['http_status']??0); return match(true){in_array($h,[401,403],true)=>'auth_error',$h===429=>'rate_limited',in_array($h,[500,502,503,504],true)=>'remote_error',($api['error_type']??null)==='transient_error'=>'transient_error',default=>$n['error_type']}; }
    private function recordResult(array $s, array $r): array
    {
        $k = $r['normalized_status'];
        if (isset($s[$k])) $s[$k]++;
        if ($r['error_type']) $s['failed']++;
        $s['recent_results'] = array_slice(array_merge([$r], $s['recent_results']), 0, 50);
        if (($r['normalized_status'] ?? null) === 'ended' && (int) ($r['http_status'] ?? 0) === 200 && ($r['error_type'] ?? null) === null && ($r['part_id'] ?? null) !== null) {
            $listingId = (int) $r['marketplace_listing_id'];
            $partId = (int) $r['part_id'];
            $s['ended_marketplace_listing_ids'] = array_values(array_unique(array_merge($s['ended_marketplace_listing_ids'] ?? [], [$listingId])));
            $s['ended_part_ids'] = array_values(array_unique(array_merge($s['ended_part_ids'] ?? [], [$partId])));
            sort($s['ended_part_ids'], SORT_NUMERIC);
            $s['ended_results_by_listing_id'][(string) $listingId] = [
                'marketplace_listing_id' => $listingId,
                'part_id' => $partId,
                'ebay_item_id' => $r['ebay_item_id'],
                'local_status' => $r['local_status'],
                'normalized_status' => $r['normalized_status'],
                'http_status' => $r['http_status'],
                'error_type' => $r['error_type'],
            ];
        }
        if ($r['error_type']) $s['last_error'] = $r['error_type'];
        return $s;
    }
    private function delayDiagnostics(array $s): array
    {
        $now = CarbonImmutable::now('UTC');
        $delaySeconds = (int) ($s['delay_seconds'] ?? 0);
        $lastBatchAt = ! empty($s['last_batch_at']) ? CarbonImmutable::parse((string) $s['last_batch_at'])->utc() : null;
        $nextAllowedAt = $lastBatchAt?->addSeconds($delaySeconds);
        $retryAfterSeconds = $nextAllowedAt ? max(0, (int) ceil($this->epochSeconds($nextAllowedAt) - $this->epochSeconds($now))) : 0;

        return [
            'server_now' => $now->toISOString(),
            'last_batch_at' => $lastBatchAt?->toISOString(),
            'delay_seconds' => $delaySeconds,
            'next_batch_allowed_at' => $nextAllowedAt?->toISOString(),
            'retry_after_seconds' => $retryAfterSeconds,
            'clock_skew_detected' => $lastBatchAt !== null && $lastBatchAt->greaterThan($now),
        ];
    }

    private function epochSeconds(CarbonImmutable $time): float
    {
        return $time->getTimestamp() + ((int) $time->format('u') / 1_000_000);
    }

    private function complete(array $s, bool $put=true): array { if(!empty($s['remaining_ids']) || (int)$s['remaining'] > 0) return $this->publicStatus($s); $s['status']='completed'; $s['finished_at']=$s['finished_at']??now()->toISOString(); if($put)Cache::put(self::CACHE_KEY,$s,now()->addHours(12)); return $this->publicStatus($s); }
    private function state(): array { $state = Cache::get(self::CACHE_KEY); if (! is_array($state)) return $this->baseState('idle') + ['_full_ended_results_key_present' => false]; return array_merge($this->baseState('idle'), $state, ['_full_ended_results_key_present' => array_key_exists('ended_results_by_listing_id', $state)]); }
    private function baseState(string $status): array { return ['status'=>$status,'marker'=>self::MARKER,'state_initialization_fix_marker'=>self::STATE_INITIALIZATION_FIX_MARKER,'dry_run'=>true,'batch_size'=>10,'delay_seconds'=>5,'total'=>0,'processed'=>0,'remaining'=>0,'active'=>0,'ended'=>0,'not_found'=>0,'invalid'=>0,'unknown'=>0,'failed'=>0,'started_at'=>null,'finished_at'=>null,'last_batch_at'=>null,'last_error'=>null,'recent_results'=>[],'ended_marketplace_listing_ids'=>[],'ended_part_ids'=>[],'ended_results_by_listing_id'=>[],'remaining_ids'=>[],'processed_ids'=>[]]; }
    public function endedProducts(): array
    {
        $raw = Cache::get(self::CACHE_KEY);
        $s = is_array($raw) ? array_merge($this->baseState('idle'), $raw) : $this->baseState('idle');
        $available = ($s['status'] ?? null) === 'completed' && is_array($raw) && array_key_exists('ended_results_by_listing_id', $raw) && is_array($raw['ended_results_by_listing_id']);
        $results = $available ? array_values($s['ended_results_by_listing_id']) : [];
        $partIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['part_id'] ?? null, $results), fn ($id) => $id !== null)));
        sort($partIds, SORT_NUMERIC);
        return [
            'ok' => true,
            'read_only' => true,
            'no_mutation' => true,
            'no_ebay_request' => true,
            'marker' => self::ENDED_PRODUCT_IDS_MARKER,
            'export_marker' => self::ENDED_PRODUCTS_EXPORT_MARKER,
            'runner_status' => $s['status'] ?? 'idle',
            'full_ended_results_available' => $available,
            'source' => $available ? 'runner_state_ended_results_by_listing_id' : 'unavailable',
            'limitation' => $available ? null : 'Current completed run contains only counters/recent_results. Start a new dry-run after this fix to collect the full ended product ID list.',
            'ended_listing_count' => $available ? count($results) : 0,
            'ended_products_count' => $available ? count($partIds) : 0,
            'ended_part_ids' => $available ? $partIds : [],
            'ended_results' => $available ? $results : [],
        ];
    }

    public function endedProductsCsv(): string
    {
        $rows = [['part_id','marketplace_listing_id','ebay_item_id','normalized_status','http_status']];
        foreach ($this->endedProducts()['ended_results'] as $r) {
            $rows[] = [$r['part_id'], $r['marketplace_listing_id'], $r['ebay_item_id'], $r['normalized_status'], $r['http_status']];
        }
        return implode("\n", array_map(fn ($row) => implode(',', array_map(fn ($v) => str_replace([',', "\n", "\r"], [' ', ' ', ' '], (string) $v), $row)), $rows))."\n";
    }

    private function publicStatus(array $s): array { $inconsistent = ($s['status'] ?? null) === 'completed' && (!empty($s['remaining_ids']) || (int)($s['remaining'] ?? 0) > 0); $delay=$this->delayDiagnostics($s); $endedResults=array_values($s['ended_results_by_listing_id'] ?? []); $endedPartIds=array_values(array_unique(array_filter($s['ended_part_ids'] ?? [], fn($id)=>$id!==null))); sort($endedPartIds, SORT_NUMERIC); $hasFullEndedResultsKey = (bool) ($s['_full_ended_results_key_present'] ?? array_key_exists('ended_results_by_listing_id',$s)); $fullEndedResultsAvailable = ($s['status'] ?? null)==='completed' && $hasFullEndedResultsKey; unset($s['remaining_ids'],$s['processed_ids'],$s['_full_ended_results_key_present']); $s = array_merge($s, ['dry_run_marker'=>self::DRY_RUN_MARKER, 'browser_autorun_marker'=>self::BROWSER_AUTORUN_MARKER, 'delay_countdown_fix_marker'=>self::DELAY_COUNTDOWN_FIX_MARKER, 'ended_product_ids_marker'=>self::ENDED_PRODUCT_IDS_MARKER, 'ended_products_export_marker'=>self::ENDED_PRODUCTS_EXPORT_MARKER, 'full_ended_results_available'=>$fullEndedResultsAvailable, 'source'=>$fullEndedResultsAvailable?'runner_state_ended_results_by_listing_id':'unavailable', 'limitation'=>$fullEndedResultsAvailable?null:'Current completed run contains only counters/recent_results. Start a new dry-run after this fix to collect the full ended product ID list.', 'ended_listing_count'=>$fullEndedResultsAvailable?count($endedResults):0, 'ended_products_count'=>$fullEndedResultsAvailable?count($endedPartIds):0, 'ended_part_ids'=>$fullEndedResultsAvailable?$endedPartIds:[]], $delay); if($inconsistent) $s = array_merge($s, ['state_inconsistent'=>true, 'reason'=>'completed_with_remaining_items']); return $s; }
}

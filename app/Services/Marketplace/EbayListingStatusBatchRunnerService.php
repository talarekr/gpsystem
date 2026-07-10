<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\EbayApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EbayListingStatusBatchRunnerService
{
    public const MARKER = 'ebay_listing_status_batch_runner_v1';
    public const DRY_RUN_MARKER = 'ebay_listing_status_batch_dry_run_v1';
    public const BROWSER_AUTORUN_MARKER = 'ebay_listing_status_batch_runner_browser_autorun_v2';
    public const STATE_INITIALIZATION_FIX_MARKER = 'ebay_listing_status_batch_runner_state_initialization_fix_v3';
    public const DELAY_COUNTDOWN_FIX_MARKER = 'ebay_listing_status_batch_runner_delay_countdown_fix_v4';
    public const CACHE_KEY = 'admin_tools:ebay_listing_status_sync:v1';
    public const RETRY_CACHE_KEY = 'admin_tools:ebay_listing_status_sync:transient_retry:v1';
    public const TRANSIENT_RETRY_MARKER = 'ebay_listing_status_transient_retry_runner_v1';
    public const RATE_LIMIT_BACKOFF_MARKER = 'ebay_listing_status_rate_limit_backoff_v1';
    public const RETRY_DIAGNOSE_MARKER = 'ebay_listing_status_retry_diagnose_v1';
    public const CONFIRMED_ENDED_DIAGNOSE_MARKER = 'ebay_confirmed_ended_results_diagnose_v1';
    public const CONFIRMED_ENDED_PREVIEW_MARKER = 'ebay_confirmed_ended_apply_preview_v1';
    public const CONFIRMED_ENDED_APPLY_MARKER = 'ebay_confirmed_ended_local_status_apply_v1';
    public const CONFIRMED_ENDED_RELISTING_MARKER = 'ebay_confirmed_ended_relisting_unblock_v1';
    public const EXPECTED_CONFIRMED_ENDED_COUNT = 378;

    public function __construct(private readonly EbayListingStatusNormalizer $normalizer) {}

    public function start(array $input): array
    {
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
        if (($input['confirm'] ?? null) !== 'run-next-ebay-listing-status-sync-batch') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $retryState = $this->retryState();
        if (in_array($retryState['status'], ['running','waiting_rate_limit'], true)) return $this->runNextRetryBatch($input);
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

    public function status(): array { return array_merge($this->publicStatus($this->state()), ['ok' => true, 'retry' => $this->publicRetryStatus($this->retryState())]); }


    public function confirmedEndedDiagnose(): array
    {
        $candidates = $this->confirmedEndedCandidatesFromState($this->state());
        $ids = $candidates['ids'];
        $full = count($ids) === self::EXPECTED_CONFIRMED_ENDED_COUNT && ! $candidates['has_duplicates'] && ! $candidates['has_unqualified'];

        return [
            'ok' => true,
            'marker' => self::CONFIRMED_ENDED_DIAGNOSE_MARKER,
            'no_mutation' => true,
            'no_ebay_request' => true,
            'expected_ended_count' => self::EXPECTED_CONFIRMED_ENDED_COUNT,
            'full_ended_id_list_available' => $full,
            'available_ended_id_count' => count($ids),
            'unique_marketplace_listing_ids' => count(array_unique($ids)),
            'data_source' => $candidates['data_source'],
            'sample' => array_slice($this->confirmedEndedRows($ids, $candidates['results']), 0, 20),
            'can_apply_without_rescan' => $full,
            'has_duplicate_ids' => $candidates['has_duplicates'],
            'has_unqualified_results' => $candidates['has_unqualified'],
        ];
    }

    public function confirmedEndedPreview(): array
    {
        $diag = $this->confirmedEndedDiagnose();
        if (! $diag['can_apply_without_rescan']) {
            return array_merge($diag, [
                'marker' => self::CONFIRMED_ENDED_PREVIEW_MARKER,
                'candidate_count' => 0,
                'can_apply' => false,
                'reason' => 'full_confirmed_ended_id_list_unavailable',
            ]);
        }

        $candidates = $this->confirmedEndedCandidatesFromState($this->state());
        $rows = $this->confirmedEndedRows($candidates['ids'], $candidates['results']);
        $productIds = array_values(array_unique(array_map(fn ($r) => (int) $r['part_id'], $rows)));
        $withOtherActive = collect($rows)->filter(fn ($r) => $this->partHasAnotherActiveEbayListing((int) $r['part_id'], (int) $r['marketplace_listing_id']))->count();
        $blocking = collect($rows)->filter(fn ($r) => (bool) $r['currently_blocks_relisting'])->count();
        $activeLocal = collect($rows)->filter(fn ($r) => in_array(strtolower((string) $r['current_local_status']), ['published','active','live'], true))->count();

        return [
            'ok' => true,
            'marker' => self::CONFIRMED_ENDED_PREVIEW_MARKER,
            'relisting_marker' => self::CONFIRMED_ENDED_RELISTING_MARKER,
            'no_mutation' => true,
            'no_ebay_request' => true,
            'can_apply' => true,
            'candidate_count' => count($rows),
            'unique_products' => count($productIds),
            'locally_still_published_active_live' => $activeLocal,
            'currently_blocks_relisting' => $blocking,
            'products_with_another_active_ebay_listing' => $withOtherActive,
            'products_will_be_unblocked' => collect($rows)->filter(fn ($r) => (bool) $r['will_unblock_relisting'])->count(),
            'sample' => array_slice($rows, 0, 50),
        ];
    }

    public function applyConfirmedEnded(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'apply-confirmed-ebay-ended-listings') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        if (($input['source'] ?? null) !== 'completed_dry_run') return ['ok' => false, 'reason' => 'invalid_source'];
        if ((int) ($input['expected_count'] ?? 0) !== self::EXPECTED_CONFIRMED_ENDED_COUNT) return ['ok' => false, 'reason' => 'expected_count_mismatch'];
        if (filter_var($input['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN)) return ['ok' => false, 'reason' => 'dry_run_required_false_for_apply'];
        $diag = $this->confirmedEndedDiagnose();
        if (! $diag['can_apply_without_rescan']) return ['ok' => false, 'reason' => 'full_confirmed_ended_id_list_unavailable', 'diagnostics' => $diag];

        $candidates = $this->confirmedEndedCandidatesFromState($this->state());
        $ids = array_slice($candidates['ids'], 0, 20);
        $updated = []; $errors = [];
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $candidates, &$updated) {
                    $listing = MarketplaceListing::query()->lockForUpdate()->findOrFail($id);
                    $result = $candidates['results'][$id] ?? [];
                    $raw = $listing->raw_payload ?: [];
                    if (! empty($result['itemEndDate'])) $raw['itemEndDate'] = $result['itemEndDate'];
                    $listing->forceFill(['status'=>'ended','last_api_status'=>'ended','last_synced_at'=>now(),'last_seen_at'=>now(),'not_seen_in_active_api_at'=>now(),'raw_payload'=>$raw])->save();
                    $updated[] = $id;
                });
            } catch (\Throwable $e) { $errors[] = ['marketplace_listing_id'=>$id, 'error'=>$e->getMessage()]; }
        }
        return ['ok'=>empty($errors),'marker'=>self::CONFIRMED_ENDED_APPLY_MARKER,'no_ebay_request'=>true,'batch_limit'=>20,'updated_count'=>count($updated),'updated_ids'=>$updated,'errors'=>$errors,'remaining_after_batch'=>count($candidates['ids'])-count($updated)];
    }

    public function retryDiagnose(): array
    {
        $state = $this->state();
        $retryIds = $this->transientFailureIdsFromState($state);
        $results = array_values($state['results_by_listing_id'] ?? []);
        $transient = array_values(array_filter($results, fn ($r) => $this->isTransientFailure($r)));

        return [
            'ok' => true,
            'marker' => self::RETRY_DIAGNOSE_MARKER,
            'no_mutation' => true,
            'no_ebay_request' => true,
            'full_retry_id_list_available' => ! empty($state['transient_failure_ids']) || ! empty($state['results_by_listing_id']),
            'unique_transient_failure_ids' => count($retryIds),
            'can_retry_without_full_rescan' => count($retryIds) > 0,
            'retry_data_source' => ! empty($state['transient_failure_ids']) ? 'cache.transient_failure_ids' : (! empty($state['results_by_listing_id']) ? 'cache.results_by_listing_id' : 'unavailable'),
            'error_type_breakdown' => array_count_values(array_map(fn ($r) => (string) ($r['error_type'] ?? 'none'), $transient)),
            'http_status_breakdown' => array_count_values(array_map(fn ($r) => (string) ($r['http_status'] ?? 'none'), $transient)),
            'sample' => array_slice($transient, 0, 20),
            'limitation' => count($retryIds) === 0 ? 'Full transient failure ID list is not present in the current cache; do not infer IDs from recent_results or rescan all listings without explicit approval.' : null,
        ];
    }

    public function retryTransient(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'retry-ebay-listing-status-transient-failures') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        if (($input['scope'] ?? 'previous_transient_failures') !== 'previous_transient_failures') return ['ok' => false, 'reason' => 'invalid_scope'];
        if (! filter_var($input['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN)) return ['ok' => false, 'reason' => 'live_mode_disabled'];
        $ids = $this->transientFailureIdsFromState($this->state());
        if ($ids === []) return ['ok' => false, 'reason' => 'no_full_transient_failure_id_list_available'];
        $batch = max(1, min((int) ($input['batch_size'] ?? 2), 5));
        $delay = max(20, (int) ($input['delay_seconds'] ?? 30));
        $max = max(1, (int) ($input['max_attempts_per_item'] ?? 3));
        $state = array_merge($this->baseRetryState('running'), ['batch_size'=>$batch,'delay_seconds'=>$delay,'max_attempts_per_item'=>$max,'retry_scope_total'=>count($ids),'pending'=>count($ids),'pending_ids'=>$ids,'started_at'=>now()->toISOString(),'original_summary'=>$this->firstSessionSummary($this->state())]);
        Cache::put(self::RETRY_CACHE_KEY, $state, now()->addHours(12));
        return array_merge($this->publicRetryStatus($state), ['ok'=>true]);
    }

    public function runNextRetryBatch(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'run-next-ebay-listing-status-sync-batch') return ['ok'=>false,'reason'=>'missing_confirm_token'];
        $state = $this->retryState();
        if (! in_array($state['status'], ['running','waiting_rate_limit'], true)) return array_merge($this->publicRetryStatus($state), ['ok'=>false,'reason'=>'not_running','batch_executed'=>false]);
        if (($wait = $this->retryWaitSeconds($state)) > 0) return array_merge($this->publicRetryStatus($state), ['ok'=>true,'reason'=>'rate_limit_wait','batch_executed'=>false,'should_wait'=>true]);
        $state['status'] = 'running';
        $batchIds = array_slice($state['pending_ids'], 0, (int) $state['batch_size']);
        foreach (MarketplaceListing::query()->whereIn('id', $batchIds)->orderBy('id')->get() as $listing) {
            $id = (int) $listing->id; $state['attempts'][$id] = (int)($state['attempts'][$id] ?? 0) + 1;
            $result = $this->checkListing($listing); $result['retry_attempt'] = $state['attempts'][$id];
            $state['retry_results_by_listing_id'][$id] = $result;
            if (($result['error_type'] ?? null) === 'rate_limited') {
                $state['rate_limit_hits']++; $state['last_error']='rate_limited';
                $seconds = $this->rateLimitDelaySeconds($result['retry_after'] ?? null, $state['attempts'][$id]);
                $state['retry_after_seconds']=$seconds; $state['next_retry_at']=now()->addSeconds($seconds)->toISOString(); $state['status']='waiting_rate_limit';
                if ($state['attempts'][$id] >= (int)$state['max_attempts_per_item']) $state=$this->markRetryUnresolved($state,$id,$result); else $state['technical_status_by_listing_id'][$id]='retry_wait';
                break;
            }
            if ($this->isTransientFailure($result)) {
                if ($state['attempts'][$id] >= (int)$state['max_attempts_per_item']) $state=$this->markRetryUnresolved($state,$id,$result); else $state['technical_status_by_listing_id'][$id]='pending_retry';
                continue;
            }
            $state = $this->markRetryResolved($state, $id, $result);
        }
        $state['pending']=count($state['pending_ids']);
        if ($state['pending'] === 0) { $state['status']='completed'; $state['finished_at']=now()->toISOString(); }
        Cache::put(self::RETRY_CACHE_KEY,$state,now()->addHours(12));
        return array_merge($this->publicRetryStatus($state), ['ok'=>true,'batch_executed'=>true]);
    }


    private function confirmedEndedCandidatesFromState(array $s): array
    {
        $source = 'unavailable'; $rows = [];
        if (! empty($s['ended_ids']) && is_array($s['ended_ids'])) {
            $source = 'ended_ids';
            foreach (array_map('intval', $s['ended_ids']) as $id) {
                $result = (array) ($s['results_by_listing_id'][$id] ?? []);
                if ($this->isConfirmedEndedResult($result)) $rows[$id] = $result;
            }
        } elseif (! empty($s['results_by_listing_id']) && is_array($s['results_by_listing_id'])) {
            $source = 'results_by_listing_id';
            foreach ($s['results_by_listing_id'] as $id => $r) if ($this->isConfirmedEndedResult((array) $r)) $rows[(int)$id] = (array) $r;
        }
        $ids = array_map('intval', array_keys($rows));
        $rawIds = $source === 'ended_ids' ? array_map('intval', $s['ended_ids']) : $ids;
        $results = [];
        foreach ($ids as $id) $results[$id] = is_array($rows[$id] ?? null) ? $rows[$id] : (array)($s['results_by_listing_id'][$id] ?? []);
        $hasUnqualified = false; foreach ($results as $r) if ($r !== [] && ! $this->isConfirmedEndedResult($r)) $hasUnqualified = true;
        return ['ids'=>array_values(array_unique($ids)), 'results'=>$results, 'data_source'=>$source, 'has_duplicates'=>count($rawIds) !== count(array_unique($rawIds)), 'has_unqualified'=>$hasUnqualified];
    }

    private function isConfirmedEndedResult(array $r): bool { return ($r['normalized_status'] ?? null) === 'ended' && (int)($r['http_status'] ?? 0) === 200 && ($r['error_type'] ?? null) === null; }

    private function confirmedEndedRows(array $ids, array $results): array
    {
        $listings = MarketplaceListing::query()->whereIn('id', $ids)->get()->keyBy('id');
        return collect($ids)->map(function (int $id) use ($listings, $results) {
            $l = $listings->get($id); $r = $results[$id] ?? [];
            $other = $l ? $this->partHasAnotherActiveEbayListing((int)$l->part_id, (int)$l->id) : false;
            $blocks = $l ? $this->blocksRelisting($l) : false;
            return ['part_id'=>$l?->part_id ?? ($r['part_id'] ?? null),'marketplace_listing_id'=>$id,'ebay_item_id'=>$r['ebay_item_id'] ?? $this->itemId($l),'local_status'=>$l?->status ?? ($r['local_status'] ?? null),'current_local_status'=>$l?->status ?? ($r['local_status'] ?? null),'planned_local_status'=>'ended','normalized_status'=>$r['normalized_status'] ?? null,'http_status'=>$r['http_status'] ?? null,'has_another_active_ebay_listing'=>$other,'currently_blocks_relisting'=>$blocks,'will_unblock_relisting'=>$blocks && ! $other];
        })->all();
    }

    private function partHasAnotherActiveEbayListing(int $partId, int $excludeId): bool
    {
        return MarketplaceListing::query()->where('part_id',$partId)->where('id','!=',$excludeId)->whereIn('marketplace',['ebay','ebay_de'])->get()->contains(fn ($l) => $this->blocksRelisting($l));
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
            'http_status' => $api['http_status'] ?? null, 'error_type' => $errorType, 'retry_after' => $api['retry_after'] ?? null, 'itemEndDate' => $api['itemEndDate'] ?? $api['item_end_date'] ?? null,
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
    private function recordResult(array $s, array $r): array { $k=$r['normalized_status']; if(isset($s[$k]))$s[$k]++; if($r['error_type'])$s['failed']++; $id=(int)$r['marketplace_listing_id']; $s['results_by_listing_id'][$id]=$r; if($r['normalized_status']==='unknown')$s['unknown_ids']=array_values(array_unique(array_merge($s['unknown_ids']??[],[$id]))); if($r['error_type'])$s['failed_ids']=array_values(array_unique(array_merge($s['failed_ids']??[],[$id]))); if($this->isTransientFailure($r))$s['transient_failure_ids']=array_values(array_unique(array_merge($s['transient_failure_ids']??[],[$id]))); $s['unknown_items']=(int)($s['unknown']??0); $s['failed_requests']=(int)($s['failed']??0); $s['unique_unresolved_items']=count(array_unique($s['unknown_ids']??[])); $s['recent_results']=array_slice(array_merge([$r],$s['recent_results']),0,50); if($r['error_type'])$s['last_error']=$r['error_type']; return $s; }
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
    private function state(): array { $state = Cache::get(self::CACHE_KEY); return is_array($state) ? array_merge($this->baseState('idle'), $state) : $this->baseState('idle'); }
    private function baseState(string $status): array { return ['status'=>$status,'marker'=>self::MARKER,'state_initialization_fix_marker'=>self::STATE_INITIALIZATION_FIX_MARKER,'dry_run'=>true,'batch_size'=>10,'delay_seconds'=>5,'total'=>0,'processed'=>0,'remaining'=>0,'active'=>0,'ended'=>0,'not_found'=>0,'invalid'=>0,'unknown'=>0,'failed'=>0,'started_at'=>null,'finished_at'=>null,'last_batch_at'=>null,'last_error'=>null,'recent_results'=>[],'remaining_ids'=>[],'processed_ids'=>[],'failed_ids'=>[],'unknown_ids'=>[],'transient_failure_ids'=>[],'results_by_listing_id'=>[],'unknown_items'=>0,'failed_requests'=>0,'unique_unresolved_items'=>0]; }


    private function isTransientFailure(array $r): bool { $h=(int)($r['http_status']??0); return ($r['normalized_status']??null)==='unknown' && in_array((string)($r['error_type']??''), ['rate_limited','timeout','network','remote_error','transient_error'], true) || in_array($h,[429,500,502,503,504],true); }
    private function transientFailureIdsFromState(array $s): array { $ids=$s['transient_failure_ids']??[]; if($ids===[] && !empty($s['results_by_listing_id'])) foreach($s['results_by_listing_id'] as $id=>$r) if($this->isTransientFailure($r)) $ids[]=(int)$id; return array_values(array_unique(array_map('intval',$ids))); }
    private function retryState(): array { $s=Cache::get(self::RETRY_CACHE_KEY); return is_array($s)?array_merge($this->baseRetryState('idle'),$s):$this->baseRetryState('idle'); }
    private function baseRetryState(string $status): array { return ['status'=>$status,'marker'=>self::TRANSIENT_RETRY_MARKER,'rate_limit_backoff_marker'=>self::RATE_LIMIT_BACKOFF_MARKER,'dry_run'=>true,'batch_size'=>2,'delay_seconds'=>30,'max_attempts_per_item'=>3,'retry_scope_total'=>0,'resolved'=>0,'pending'=>0,'resolved_active'=>0,'resolved_ended'=>0,'resolved_not_found'=>0,'unresolved_after_max_attempts'=>0,'rate_limit_hits'=>0,'retry_after_seconds'=>0,'next_retry_at'=>null,'pending_ids'=>[],'resolved_ids'=>[],'unresolved_ids'=>[],'attempts'=>[],'technical_status_by_listing_id'=>[],'retry_results_by_listing_id'=>[],'started_at'=>null,'finished_at'=>null,'last_error'=>null,'original_summary'=>[]]; }
    private function publicRetryStatus(array $s): array { $wait=$this->retryWaitSeconds($s); if($wait===0 && ($s['status']??null)==='waiting_rate_limit') $s['status']='running'; unset($s['pending_ids'],$s['resolved_ids'],$s['unresolved_ids'],$s['attempts']); return array_merge($s,['retry_after_seconds'=>$wait,'consolidated_report'=>$this->consolidatedReport($s)]); }
    private function retryWaitSeconds(array $s): int { if(empty($s['next_retry_at'])) return 0; return max(0, CarbonImmutable::parse($s['next_retry_at'])->getTimestamp()-now()->timestamp); }
    private function rateLimitDelaySeconds(?string $retryAfter, int $attempt): int { $base=null; if(filled($retryAfter)){ if(ctype_digit(trim($retryAfter))) $base=(int)trim($retryAfter); elseif(($ts=strtotime($retryAfter))!==false) $base=max(0,$ts-now()->timestamp); } $base ??= [1=>60,2=>120][min($attempt,2)] ?? 300; return max(1, $base + random_int(1,5)); }
    private function markRetryResolved(array $s, int $id, array $r): array { $s['pending_ids']=array_values(array_diff($s['pending_ids'],[$id])); $s['resolved_ids'][]=$id; $s['resolved']=count(array_unique($s['resolved_ids'])); $map=['active'=>'resolved_active','ended'=>'resolved_ended','not_found'=>'resolved_not_found']; $key=$map[$r['normalized_status']]??null; if($key)$s[$key]++; $s['technical_status_by_listing_id'][$id]=$key ?? 'resolved_unknown'; return $s; }
    private function markRetryUnresolved(array $s, int $id, array $r): array { $s['pending_ids']=array_values(array_diff($s['pending_ids'],[$id])); $s['unresolved_ids'][]=$id; $s['unresolved_after_max_attempts']=count(array_unique($s['unresolved_ids'])); $s['technical_status_by_listing_id'][$id]='unresolved_after_max_attempts'; return $s; }
    private function firstSessionSummary(array $s): array { return collect($s)->only(['active','ended','not_found','unknown','failed','unknown_items','failed_requests','unique_unresolved_items'])->all(); }
    private function consolidatedReport(array $s): array { $o=$s['original_summary']??[]; return ['active'=>(int)($o['active']??0)+(int)($s['resolved_active']??0),'ended'=>(int)($o['ended']??0)+(int)($s['resolved_ended']??0),'not_found'=>(int)($o['not_found']??0)+(int)($s['resolved_not_found']??0),'unresolved'=>(int)($s['unresolved_after_max_attempts']??0)]; }

    private function publicStatus(array $s): array { $inconsistent = ($s['status'] ?? null) === 'completed' && (!empty($s['remaining_ids']) || (int)($s['remaining'] ?? 0) > 0); $delay=$this->delayDiagnostics($s); unset($s['remaining_ids'],$s['processed_ids']); $s = array_merge($s, ['dry_run_marker'=>self::DRY_RUN_MARKER, 'browser_autorun_marker'=>self::BROWSER_AUTORUN_MARKER, 'delay_countdown_fix_marker'=>self::DELAY_COUNTDOWN_FIX_MARKER], $delay); if($inconsistent) $s = array_merge($s, ['state_inconsistent'=>true, 'reason'=>'completed_with_remaining_items']); return $s; }
}

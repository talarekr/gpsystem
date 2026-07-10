<?php

namespace App\Services\Marketplace;

use App\Models\EbayListingStatusScanResult;
use App\Models\EbayListingStatusScanRun;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\EbayApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EbayListingStatusPersistentScanService
{
    public const SCAN_MARKER = 'ebay_listing_status_persistent_scan_v1';
    public const RESULTS_MARKER = 'ebay_listing_status_persistent_results_v1';
    public const RATE_LIMIT_MARKER = 'ebay_listing_status_persistent_rate_limit_pause_v1';
    public const ENDED_RESULTS_MARKER = 'ebay_listing_status_persistent_ended_results_v1';

    public function __construct(private readonly EbayListingStatusNormalizer $normalizer) {}

    public function start(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'start-persistent-ebay-listing-status-scan') return ['ok'=>false,'reason'=>'missing_confirm_token'];
        if (($input['scope'] ?? 'products_with_ebay_item_id') !== 'products_with_ebay_item_id') return ['ok'=>false,'reason'=>'invalid_scope'];
        if (! filter_var($input['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN)) return ['ok'=>false,'reason'=>'live_mode_disabled'];
        $ids = $this->eligibleListingIds();
        $settings = ['batch_size'=>max(1, min((int)($input['batch_size'] ?? 2), 3)), 'delay_seconds'=>max(15, (int)($input['delay_seconds'] ?? 20)), 'scope'=>'products_with_ebay_item_id', 'dry_run'=>true, 'stop_on_rate_limit'=>true, 'max_attempts_per_item'=>max(1, (int)($input['max_attempts_per_item'] ?? 3)), 'persist_full_report'=>true];
        $run = DB::transaction(function () use ($ids, $settings) {
            $run = EbayListingStatusScanRun::query()->create(['mode'=>'persistent_full','status'=>'running','dry_run'=>true,'total'=>count($ids),'remaining'=>count($ids),'started_at'=>now(),'settings'=>$settings,'summary'=>['markers'=>[self::SCAN_MARKER,self::RESULTS_MARKER]]]);
            foreach (MarketplaceListing::query()->whereIn('id', $ids)->orderBy('id')->get() as $listing) {
                EbayListingStatusScanResult::query()->create($this->initialResult($run->id, $listing));
            }
            return $run->fresh();
        });
        return array_merge($this->publicStatus($run), ['ok'=>true]);
    }

    public function status(): array { return array_merge($this->publicStatus($this->currentRun()), ['ok'=>true]); }
    public function stop(array $input): array { if(($input['confirm']??null)!=='stop-persistent-ebay-listing-status-scan') return ['ok'=>false,'reason'=>'missing_confirm_token']; $run=$this->currentRun(); if($run && in_array($run->status,['running','waiting_rate_limit'],true)){$run->update(['status'=>'stopped','finished_at'=>now()]);} return array_merge($this->publicStatus($run?->fresh()), ['ok'=>true]); }
    public function diagnose(): array { $run=$this->currentRun(); return ['ok'=>true,'marker'=>self::SCAN_MARKER,'no_mutation'=>true,'no_ebay_request'=>true,'scan_run_id'=>$run?->id,'status'=>$run?->status ?? 'idle','runs_count'=>EbayListingStatusScanRun::query()->count(),'results_count'=>$run?->results()->count() ?? 0]; }

    public function runNextBatch(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'run-next-persistent-ebay-listing-status-scan-batch') return ['ok'=>false,'reason'=>'missing_confirm_token'];
        $run = $this->currentRun();
        if (! $run || ! in_array($run->status, ['running','waiting_rate_limit'], true)) return array_merge($this->publicStatus($run), ['ok'=>false,'reason'=>'not_running','batch_executed'=>false]);
        if (($wait=$this->waitSeconds($run)) > 0) return array_merge($this->publicStatus($run), ['ok'=>true,'reason'=>'rate_limit_wait','batch_executed'=>false,'should_wait'=>true]);
        $run->update(['status'=>'running']);
        $pending = $this->pendingQuery($run)->limit((int)($run->settings['batch_size'] ?? 2))->get();
        if ($pending->isEmpty()) return array_merge($this->completeIfDone($run), ['ok'=>true,'batch_executed'=>false]);
        foreach ($pending as $result) {
            $listing = MarketplaceListing::query()->find($result->marketplace_listing_id); if (! $listing) { $this->saveTerminal($result, ['normalized_status'=>'unknown','error_type'=>'missing_local_listing','http_status'=>null], true); continue; }
            $apiResult = $this->checkListing($listing); $attempts = $result->attempts + 1; $isRate = ($apiResult['error_type'] ?? null) === 'rate_limited';
            if ($isRate) {
                $this->saveTransientOrUnknown($result, $apiResult, $attempts, (int)($run->settings['max_attempts_per_item'] ?? 3));
                $seconds = $this->rateLimitDelaySeconds($apiResult['retry_after'] ?? null, $attempts);
                $run->increment('rate_limit_hits'); $run->update(['status'=>'waiting_rate_limit','summary'=>array_merge($run->summary ?? [], ['next_retry_at'=>now()->addSeconds($seconds)->toISOString(), 'retry_after_seconds'=>$seconds, 'rate_limit_marker'=>self::RATE_LIMIT_MARKER])]);
                break;
            }
            if ($this->isTransientFailure($apiResult) && $attempts < (int)($run->settings['max_attempts_per_item'] ?? 3)) { $this->saveTransientOrUnknown($result, $apiResult, $attempts, 999); continue; }
            $this->saveTerminal($result, $apiResult, $this->isTransientFailure($apiResult));
        }
        $this->refreshCounters($run->fresh());
        return array_merge($this->publicStatus($this->currentRun()), ['ok'=>true,'batch_executed'=>true]);
    }

    public function endedResults(?int $scanRunId): array
    {
        $run = $scanRunId ? EbayListingStatusScanRun::query()->find($scanRunId) : $this->currentRun();
        $q = $run?->results()->where('normalized_status','ended')->where('http_status',200)->whereNull('error_type');
        $ids = $q ? $q->pluck('marketplace_listing_id')->map(fn($v)=>(int)$v)->all() : [];
        return ['ok'=>true,'marker'=>self::ENDED_RESULTS_MARKER,'scan_run_id'=>$run?->id,'run_status'=>$run?->status ?? 'idle','full_ended_id_list_available'=>$run?->status === 'completed','ended_count'=>count($ids),'unique_marketplace_listing_ids'=>count(array_unique($ids)),'unqualified_count'=>$run ? $run->results()->where('normalized_status','ended')->where(fn($qq)=>$qq->where('http_status','!=',200)->orWhereNotNull('error_type'))->count() : 0,'can_apply_confirmed_ended'=>$run?->status === 'completed' && count($ids) > 0,'sample'=>$q ? $q->limit(50)->get()->map(fn($r)=>$r->only(['marketplace_listing_id','part_id','ebay_item_id','local_status','normalized_status','http_status','error_type','attempts','currently_blocks_relisting','should_allow_relisting','item_end_date','checked_at']))->all() : [],'no_mutation'=>true,'no_ebay_request'=>true];
    }

    private function currentRun(): ?EbayListingStatusScanRun { return EbayListingStatusScanRun::query()->latest('id')->first(); }
    private function pendingQuery(EbayListingStatusScanRun $run) { return $run->results()->where(function($q){$q->whereNull('checked_at')->orWhere('error_type','rate_limited')->orWhere('error_type','remote_error')->orWhere('error_type','transient_error');})->where('attempts','<',(int)($run->settings['max_attempts_per_item'] ?? 3))->orderBy('id'); }
    private function initialResult(int $runId, MarketplaceListing $l): array { return ['scan_run_id'=>$runId,'marketplace_listing_id'=>$l->id,'part_id'=>$l->part_id,'ebay_item_id'=>$this->itemId($l),'local_status'=>$l->status,'normalized_status'=>'unknown','attempts'=>0,'currently_blocks_relisting'=>$this->blocksRelisting($l),'should_allow_relisting'=>false,'diagnostic'=>['state'=>'pending']]; }
    private function saveTransientOrUnknown(EbayListingStatusScanResult $r, array $api, int $attempts, int $max): void { $this->saveTerminal($r, array_merge($api, ['normalized_status'=>$attempts >= $max ? 'unknown' : ($api['normalized_status'] ?? 'unknown')]), true, $attempts); }
    private function saveTerminal(EbayListingStatusScanResult $r, array $api, bool $failed, ?int $attempts=null): void { $r->update(['normalized_status'=>$api['normalized_status'] ?? 'unknown','http_status'=>$api['http_status'] ?? null,'error_type'=>$failed ? ($api['error_type'] ?? 'transient_error') : ($api['error_type'] ?? null),'attempts'=>$attempts ?? ($r->attempts + 1),'should_allow_relisting'=>(bool)($api['should_allow_relisting'] ?? false),'item_end_date'=>!empty($api['itemEndDate']) ? CarbonImmutable::parse($api['itemEndDate']) : null,'checked_at'=>now(),'diagnostic'=>$api]); }
    private function refreshCounters(EbayListingStatusScanRun $run): void { $counts=$run->results()->select('normalized_status', DB::raw('count(*) as c'))->whereNotNull('checked_at')->groupBy('normalized_status')->pluck('c','normalized_status'); $processed=$run->results()->whereNotNull('checked_at')->where(function($q){$q->whereNull('error_type')->orWhereNotIn('error_type',['rate_limited','remote_error','transient_error']);})->count(); $remaining=$run->total-$processed; $attrs=['processed'=>$processed,'remaining'=>$remaining,'active'=>(int)($counts['active']??0),'ended'=>(int)($counts['ended']??0),'not_found'=>(int)($counts['not_found']??0),'invalid'=>(int)($counts['invalid']??0),'unknown'=>(int)($counts['unknown']??0),'failed_requests'=>$run->results()->whereNotNull('error_type')->count()]; if($remaining===0){$attrs['status']='completed';$attrs['finished_at']=now();} $run->update($attrs); }
    private function completeIfDone(EbayListingStatusScanRun $run): array { $this->refreshCounters($run); return $this->publicStatus($run->fresh()); }
    private function waitSeconds(EbayListingStatusScanRun $run): int { $at=$run->summary['next_retry_at'] ?? null; return $run->status==='waiting_rate_limit' && $at ? max(0, CarbonImmutable::parse($at)->timestamp-now()->timestamp) : 0; }
    private function publicStatus(?EbayListingStatusScanRun $run): array { if(!$run) return ['scan_run_id'=>null,'status'=>'idle','total'=>0,'processed'=>0,'remaining'=>0,'active'=>0,'ended'=>0,'not_found'=>0,'invalid'=>0,'unknown'=>0,'failed_requests'=>0,'rate_limit_hits'=>0,'next_retry_at'=>null,'retry_after_seconds'=>0,'last_processed_listing_id'=>null,'marker'=>self::SCAN_MARKER]; $wait=$this->waitSeconds($run); return ['scan_run_id'=>$run->id,'status'=>$wait===0&&$run->status==='waiting_rate_limit'?'running':$run->status,'total'=>$run->total,'processed'=>$run->processed,'remaining'=>$run->remaining,'active'=>$run->active,'ended'=>$run->ended,'not_found'=>$run->not_found,'invalid'=>$run->invalid,'unknown'=>$run->unknown,'failed_requests'=>$run->failed_requests,'rate_limit_hits'=>$run->rate_limit_hits,'next_retry_at'=>$run->summary['next_retry_at'] ?? null,'retry_after_seconds'=>$wait,'last_processed_listing_id'=>$run->results()->whereNotNull('checked_at')->latest('checked_at')->value('marketplace_listing_id'),'marker'=>self::SCAN_MARKER,'results_marker'=>self::RESULTS_MARKER]; }
    private function eligibleListingIds(): array { return MarketplaceListing::query()->whereIn('marketplace',['ebay','ebay_de'])->orderBy('id')->get()->filter(fn($l)=>filled($this->itemId($l)))->pluck('id')->values()->all(); }
    private function checkListing(MarketplaceListing $listing): array { try { $channel=$listing->marketplace==='ebay_de'?'ebay_de':'ebay'; $api=(new EbayApiClient($channel, MarketplaceAccount::query()->where('code',$channel)->first()))->getListingStatusByItemId((string)$this->itemId($listing)); } catch (\Throwable $e) { $api=['http_status'=>null,'api_listing_status'=>'unknown','error_type'=>'transient_error','error'=>$e->getMessage()]; } $n=$this->normalizer->normalize($api); $h=(int)($api['http_status']??0); $err=match(true){$h===429=>'rate_limited',in_array($h,[500,502,503,504],true)=>'remote_error',($api['error_type']??null)==='transient_error'=>'transient_error',default=>$n['error_type']}; return ['part_id'=>$listing->part_id,'marketplace_listing_id'=>$listing->id,'ebay_item_id'=>$this->itemId($listing),'local_status'=>$listing->status,'normalized_status'=>$n['normalized_status'],'currently_blocks_relisting'=>$this->blocksRelisting($listing),'should_allow_relisting'=>$n['should_allow_relisting'],'http_status'=>$api['http_status']??null,'error_type'=>$err,'retry_after'=>$api['retry_after']??null,'itemEndDate'=>$api['itemEndDate']??$api['end_date']??null]; }
    private function itemId(?MarketplaceListing $listing): ?string { foreach([$listing?->external_listing_id,$listing?->external_offer_id] as $v) if(is_string($v)&&preg_match('/\d{6,}/',$v,$m)) return $m[0]; if($listing&&preg_match('#/itm/(\d+)#',(string)$listing->url,$m)) return $m[1]; foreach(['itemId','item_id','ebay_item_id'] as $key) if($listing&&preg_match('/\d{6,}/',(string)Arr::get($listing->raw_payload,$key),$m)) return $m[0]; return null; }
    private function blocksRelisting(MarketplaceListing $l): bool { return in_array(strtolower((string)$l->status),['active','published','live'],true) && ! in_array(strtolower((string)$l->last_api_status),['ended','failed','deleted','archived','not_found','inactive','unavailable','error'],true) && $l->not_seen_in_active_api_at===null; }
    private function isTransientFailure(array $r): bool { return in_array((int)($r['http_status']??0),[429,500,502,503,504],true) || in_array((string)($r['error_type']??''),['rate_limited','remote_error','transient_error'],true); }
    private function rateLimitDelaySeconds(?string $retryAfter, int $attempt): int { $base=null; if(filled($retryAfter)){ if(ctype_digit(trim($retryAfter))) $base=(int)trim($retryAfter); elseif(($ts=strtotime($retryAfter))!==false) $base=max(0,$ts-now()->timestamp); } $base ??= [1=>60,2=>120][min($attempt,2)] ?? 300; return max(1,$base+random_int(1,5)); }
}

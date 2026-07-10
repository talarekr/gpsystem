<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class EbayListingStatusBatchRunnerService
{
    public const MARKER = 'ebay_listing_status_batch_runner_v1';
    public const DRY_RUN_MARKER = 'ebay_listing_status_batch_dry_run_v1';
    private const KEY = 'admin_tools:ebay_listing_status_sync:v1';

    public function __construct(private readonly EbayListingStatusNormalizer $normalizer) {}

    public function start(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'start-ebay-listing-status-sync') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        if (($input['scope'] ?? 'products_with_ebay_item_id') !== 'products_with_ebay_item_id') return ['ok' => false, 'reason' => 'invalid_scope'];
        if (! filter_var($input['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN)) return ['ok' => false, 'reason' => 'live_mode_disabled'];
        $batch = max(1, min((int) ($input['batch_size'] ?? 10), 20));
        $delay = max(5, (int) ($input['delay_seconds'] ?? 5));
        $ids = $this->eligibleListingIds();
        $state = $this->baseState('running') + [
            'dry_run' => true, 'batch_size' => $batch, 'delay_seconds' => $delay, 'total' => count($ids),
            'remaining_ids' => $ids, 'processed_ids' => [], 'started_at' => now()->toISOString(),
        ];
        $state['remaining'] = count($ids);
        Cache::put(self::KEY, $state, now()->addHours(12));
        return $this->publicStatus($state) + ['ok' => true];
    }

    public function stop(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'stop-ebay-listing-status-sync') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $state = $this->state();
        if ($state['status'] === 'running') $state['status'] = 'stopped';
        $state['finished_at'] = $state['finished_at'] ?? now()->toISOString();
        Cache::put(self::KEY, $state, now()->addHours(12));
        return $this->publicStatus($state) + ['ok' => true];
    }

    public function runNextBatch(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'run-next-ebay-listing-status-sync-batch') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $state = $this->state();
        if ($state['status'] !== 'running') return $this->publicStatus($state) + ['ok' => false, 'reason' => 'not_running'];
        if (empty($state['remaining_ids'])) return $this->complete($state) + ['ok' => true];
        if ($state['last_batch_at'] && now()->diffInSeconds(\Carbon\Carbon::parse($state['last_batch_at']), false) > -((int) $state['delay_seconds'])) return $this->publicStatus($state) + ['ok' => false, 'reason' => 'delay_not_elapsed'];

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
        if ($state['remaining'] === 0) $state = $this->complete($state, false);
        Cache::put(self::KEY, $state, now()->addHours(12));
        return $this->publicStatus($state) + ['ok' => true];
    }

    public function status(): array { return $this->publicStatus($this->state()) + ['ok' => true]; }

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
    private function recordResult(array $s, array $r): array { $k=$r['normalized_status']; if(isset($s[$k]))$s[$k]++; if($r['error_type'])$s['failed']++; $s['recent_results']=array_slice(array_merge([$r],$s['recent_results']),0,50); if($r['error_type'])$s['last_error']=$r['error_type']; return $s; }
    private function complete(array $s, bool $put=true): array { $s['status']='completed'; $s['finished_at']=$s['finished_at']??now()->toISOString(); if($put)Cache::put(self::KEY,$s,now()->addHours(12)); return $this->publicStatus($s); }
    private function state(): array { $state = Cache::get(self::KEY); return is_array($state) ? ($state + $this->baseState('idle')) : $this->baseState('idle'); }
    private function baseState(string $status): array { return ['status'=>$status,'marker'=>self::MARKER,'dry_run'=>true,'batch_size'=>10,'delay_seconds'=>5,'total'=>0,'processed'=>0,'remaining'=>0,'active'=>0,'ended'=>0,'not_found'=>0,'invalid'=>0,'unknown'=>0,'failed'=>0,'started_at'=>null,'finished_at'=>null,'last_batch_at'=>null,'last_error'=>null,'recent_results'=>[],'remaining_ids'=>[],'processed_ids'=>[]]; }
    private function publicStatus(array $s): array { unset($s['remaining_ids'],$s['processed_ids']); return $s + ['dry_run_marker'=>self::DRY_RUN_MARKER]; }
}

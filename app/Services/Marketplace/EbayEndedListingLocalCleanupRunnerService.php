<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\EbayApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class EbayEndedListingLocalCleanupRunnerService
{
    public const MARKER = 'ebay_ended_listing_local_cleanup_runner_v1';
    public const CACHE_KEY = 'admin_tools:ebay_ended_listing_local_cleanup_runner:v1';

    public function __construct(private readonly EbayListingStatusNormalizer $normalizer) {}

    public function start(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'start-ebay-listing-status-audit-runner') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $mode = (string) ($input['mode'] ?? 'dry_run');
        if (! in_array($mode, ['dry_run', 'live'], true)) return ['ok' => false, 'reason' => 'invalid_mode'];
        $ids = $this->candidateIds();
        $state = array_merge($this->baseState('running'), [
            'mode' => $mode,
            'batch_size' => max(1, min((int) ($input['batch_size'] ?? 10), 50)),
            'delay_seconds' => max(0, (int) ($input['delay_seconds'] ?? 2)),
            'total_candidates_at_start' => count($ids),
            'remaining' => count($ids),
            'remaining_ids' => $ids,
            'started_at' => now()->toISOString(),
        ]);
        Cache::put(self::CACHE_KEY, $state, now()->addHours(12));
        return ['ok' => true] + $this->publicStatus($state);
    }

    public function stop(): array
    {
        $state = $this->state();
        if ($state['status'] === 'running') $state['status'] = 'stopped';
        $state['finished_at'] ??= now()->toISOString();
        Cache::put(self::CACHE_KEY, $state, now()->addHours(12));
        return ['ok' => true] + $this->publicStatus($state);
    }

    public function runNextBatch(): array
    {
        $state = $this->state();
        if ($state['status'] !== 'running') return ['ok' => false, 'reason' => 'not_running'] + $this->publicStatus($state);
        $delay = $this->delayDiagnostics($state);
        if ($delay['retry_after_seconds'] > 0) return ['ok' => true, 'batch_executed' => false, 'reason' => 'delay_not_elapsed'] + $this->publicStatus($state);
        $ids = array_slice($state['remaining_ids'], 0, (int) $state['batch_size']);
        if ($ids === []) return ['ok' => true, 'completed' => true] + $this->complete($state);
        foreach (MarketplaceListing::query()->with('part')->whereIn('id', $ids)->orderBy('id')->get() as $listing) {
            $result = $this->inspect($listing, $state['mode'] === 'live');
            $state = $this->record($state, $result);
        }
        $state['remaining_ids'] = array_values(array_diff($state['remaining_ids'], $ids));
        $state['processed'] = (int) $state['processed'] + count($ids);
        $state['remaining'] = count($state['remaining_ids']);
        $state['last_batch_at'] = now()->toISOString();
        if ($state['remaining'] === 0) $state = $this->complete($state, false);
        Cache::put(self::CACHE_KEY, $state, now()->addHours(12));
        return ['ok' => true, 'batch_executed' => true] + $this->publicStatus($state);
    }

    public function status(): array { return ['ok' => true] + $this->publicStatus($this->state()); }

    public function diagnosePart(int $partId): array
    {
        $listing = MarketplaceListing::query()->where('part_id', $partId)->whereIn('marketplace', ['ebay', 'ebay_de'])->latest('id')->first();
        return ['ok' => true, 'read_only' => true, 'marker' => self::MARKER, 'part_id' => $partId] + ($listing ? $this->inspect($listing, false) : ['local' => null, 'remote_eBay_status' => 'missing_local_listing', 'would_cleanup' => false, 'cleanup_reason' => 'missing_local_listing']);
    }

    private function inspect(MarketplaceListing $listing, bool $live): array
    {
        $itemId = $this->itemId($listing);
        $base = ['part_id' => $listing->part_id, 'local_sku' => $listing->sku ?: $listing->part?->sku, 'marketplace_listing_id' => $listing->id, 'ebay_item_id' => $itemId, 'offer_id' => $listing->external_offer_id, 'inventory_id' => $listing->external_inventory_id, 'local_status' => $listing->status, 'local_sync_status' => $listing->sync_status, 'local_match_status' => $listing->match_status, 'local_last_api_status' => $listing->last_api_status, 'local_url' => $listing->url];
        if (! $this->isCandidate($listing) || ! $itemId) return $base + ['remote_eBay_status' => 'skipped', 'remote_status_source' => 'local_candidate_filter', 'would_cleanup' => false, 'cleanup_reason' => 'not_active_local_candidate', 'cleaned' => false];
        try { $api = $this->fetchRemote($listing, $itemId); } catch (\Throwable $e) { $api = ['http_status' => null, 'error_type' => 'transient_error', 'error' => $e->getMessage()]; }
        $normalized = $this->normalizer->normalize($api);
        $remote = $normalized['normalized_status'];
        $would = in_array($remote, ['ended', 'not_found'], true) && $normalized['error_type'] === null;
        $result = $base + ['remote_eBay_status' => $remote, 'remote_status_source' => 'ebay_buy_browse_item_lookup', 'http_status' => $api['http_status'] ?? null, 'api_error_type' => $normalized['error_type'], 'would_cleanup' => $would, 'cleanup_reason' => $would ? 'remote_listing_'.$remote : ($remote === 'active' ? 'remote_listing_active' : 'remote_status_not_safe_for_cleanup'), 'cleaned' => false, 'proposed_cleanup_fields' => $this->cleanupFields($listing, $remote)];
        if ($live && $would && $this->isCandidate($listing->fresh())) $result['cleaned'] = $this->cleanup($listing->fresh(), $remote, $result['cleanup_reason']);
        return $result;
    }

    private function fetchRemote(MarketplaceListing $listing, string $itemId): array
    {
        $channel = $listing->marketplace === 'ebay_de' ? 'ebay_de' : 'ebay_de';
        return (new EbayApiClient($channel, MarketplaceAccount::query()->where('code', $channel)->first()))->getListingStatusByItemId($itemId);
    }

    private function cleanup(MarketplaceListing $listing, string $remote, string $reason): bool
    {
        $raw = $listing->raw_payload ?: [];
        $raw['metadata'] = array_merge((array) ($raw['metadata'] ?? []), [
            'previous_external_offer_id' => $listing->external_offer_id, 'previous_external_listing_id' => $listing->external_listing_id, 'previous_external_inventory_id' => $listing->external_inventory_id, 'previous_url' => $listing->url, 'previous_status' => $listing->status, 'previous_sync_status' => $listing->sync_status, 'previous_match_status' => $listing->match_status, 'previous_last_api_status' => $listing->last_api_status, 'cleanup_reason' => $reason, 'cleanup_checked_at' => now()->toISOString(), 'cleanup_remote_status' => $remote,
        ]);
        return $listing->forceFill(['external_offer_id' => null, 'external_listing_id' => null, 'url' => null, 'status' => 'ended', 'sync_status' => 'stale', 'match_status' => 'unmatched', 'last_api_status' => $remote === 'not_found' ? 'not_found' : 'remote_ended', 'not_seen_in_active_api_at' => now(), 'raw_payload' => $raw])->save();
    }

    private function candidateIds(): array { return MarketplaceListing::query()->whereIn('marketplace', ['ebay', 'ebay_de'])->whereNotNull('part_id')->orderBy('id')->get()->filter(fn ($l) => $this->isCandidate($l) && filled($this->itemId($l)))->pluck('id')->values()->all(); }
    private function isCandidate(?MarketplaceListing $l): bool { if (! $l || ! in_array($l->marketplace, ['ebay','ebay_de'], true) || ! $l->part_id) return false; $s=strtolower((string)$l->status); $sync=strtolower((string)$l->sync_status); $match=strtolower((string)$l->match_status); $api=strtolower((string)$l->last_api_status); return (filled($l->external_offer_id)||filled($l->external_listing_id)||filled($l->url)) && (in_array($s,['active','published','live'],true)||in_array($sync,['active','published','synced','mapped'],true)||in_array($match,['matched','confirmed','manual_matched'],true)) && ! in_array($s,['ended','unlinked','stale','unmatched','deleted','archived'],true) && ! in_array($api,['ended','remote_ended','not_found','deleted','archived','unavailable'],true); }
    private function itemId(?MarketplaceListing $l): ?string { foreach ([$l?->external_listing_id, $l?->external_offer_id, $l?->external_inventory_id] as $v) if (is_string($v) && preg_match('/\d{6,}/', $v, $m)) return $m[0]; if ($l && preg_match('#/itm/(\d+)#', (string)$l->url, $m)) return $m[1]; foreach (['itemId','item_id','ebay_item_id'] as $k) if ($l && preg_match('/\d{6,}/', (string) Arr::get($l->raw_payload, $k), $m)) return $m[0]; return null; }
    private function cleanupFields(MarketplaceListing $l, string $remote): array { return ['external_offer_id'=>null,'external_listing_id'=>null,'url'=>null,'external_inventory_id'=>'unchanged','status'=>'ended','sync_status'=>'stale','match_status'=>'unmatched','last_api_status'=>$remote === 'not_found' ? 'not_found' : 'remote_ended']; }
    private function record(array $s, array $r): array { $key = match($r['remote_eBay_status'] ?? null){'active'=>'active_count','ended'=>'ended_count','not_found'=>'not_found_count', default => ($r['api_error_type'] ?? null) ? 'failed_count' : 'skipped_count'}; $s[$key]++; if (($r['cleaned'] ?? false) === true) $s['cleaned_count']++; $s['last_batch_results'] = array_slice(array_merge([$r], $s['last_batch_results']), 0, 50); return $s; }
    private function complete(array $s, bool $put=true): array { $s['status']='completed'; $s['finished_at'] ??= now()->toISOString(); if($put) Cache::put(self::CACHE_KEY,$s,now()->addHours(12)); return $s; }
    private function state(): array { $s=Cache::get(self::CACHE_KEY); return is_array($s) ? array_merge($this->baseState('idle'), $s) : $this->baseState('idle'); }
    private function baseState(string $status): array { return ['marker'=>self::MARKER,'status'=>$status,'mode'=>'dry_run','batch_size'=>10,'delay_seconds'=>2,'total_candidates_at_start'=>0,'processed'=>0,'active_count'=>0,'ended_count'=>0,'not_found_count'=>0,'skipped_count'=>0,'failed_count'=>0,'cleaned_count'=>0,'remaining'=>0,'remaining_ids'=>[],'last_batch_results'=>[],'started_at'=>null,'finished_at'=>null,'last_batch_at'=>null,'errors'=>[]]; }
    private function delayDiagnostics(array $s): array { $last = !empty($s['last_batch_at']) ? CarbonImmutable::parse($s['last_batch_at']) : null; $next=$last?->addSeconds((int)$s['delay_seconds']); return ['next_batch_allowed_at'=>$next?->toISOString(),'retry_after_seconds'=>$next ? max(0, $next->diffInSeconds(now(), false) * -1) : 0]; }
    private function publicStatus(array $s): array { return array_merge(Arr::except($s, ['remaining_ids']), $this->delayDiagnostics($s)); }
}

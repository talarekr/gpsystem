<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OvokoPartMappingResetRunnerService
{
    public const MARKER = 'ovoko_part_mapping_reset_runner_routes_debug_fix_v4';
    private const STATE_PATH = 'admin-tools/ovoko-part-mapping-reset-runner.json';
    private const START_CONFIRM = 'start-ovoko-part-mapping-reset-runner';
    private const BATCH_CONFIRM = 'run-ovoko-part-mapping-reset-runner-batch';
    private const STOP_CONFIRM = 'stop-ovoko-part-mapping-reset-runner';

    public function status(): array
    {
        try {
            return $this->withComputed($this->readState());
        } catch (\Throwable $e) {
            return $this->exceptionResult($e, 'read_status') + $this->emptyState();
        }
    }

    public function start(array $input): array
    {
        if (($input['confirm'] ?? null) !== self::START_CONFIRM) return ['ok' => false, 'reason' => 'missing_confirm_token', 'phase' => 'start'];

        try {
            $mode = in_array(($input['mode'] ?? 'dry_run'), ['dry_run', 'live'], true) ? $input['mode'] : 'dry_run';
            $batchSize = max(1, min(25, (int) ($input['batch_size'] ?? 10)));
            $delay = max(1, (int) ($input['delay_seconds'] ?? 2));
            $ids = $this->candidateIds();
        } catch (\Throwable $e) {
            return $this->exceptionResult($e, 'query_candidates');
        }

        try {
            $state = array_merge($this->emptyState(), [
                'mode' => $mode,
                'status' => 'running',
                'started_at' => now()->toISOString(),
                'finished_at' => null,
                'batch_size' => $batchSize,
                'delay_seconds' => $delay,
                'total_candidates_at_start' => count($ids),
                'candidate_ids' => array_values(array_map('intval', $ids)),
                'marker_position' => 0,
            ]);
            $this->writeState($state);
        } catch (\Throwable $e) {
            return $this->exceptionResult($e, 'save_state');
        }

        return ['ok' => true] + $this->withComputed($state);
    }

    public function debug(): array
    {
        $state = null;
        $stateError = null;
        $candidateError = null;
        $preview = null;
        $canQuery = false;

        try {
            $state = $this->status();
        } catch (\Throwable $e) {
            $stateError = ['error_class' => $e::class, 'message' => $e->getMessage()];
            $state = $this->emptyState();
            $state['ok'] = false;
        }

        try {
            $ids = $this->candidateIds();
            $canQuery = true;
            $previewId = $ids[0] ?? null;
            if ($previewId) {
                $part = Part::query()->with(['marketplaceListings' => fn ($q) => $q->where('marketplace', 'ovoko')->orderByDesc('id')])->find($previewId);
                $listing = $part?->marketplaceListings->first();
                $preview = ($part && $listing) ? $this->resultPayload($part, $listing) : null;
            }
        } catch (\Throwable $e) {
            $canQuery = false;
            $candidateError = ['error_class' => $e::class, 'message' => $e->getMessage()];
        }

        return [
            'ok' => $stateError === null && $candidateError === null,
            'marker' => self::MARKER,
            'route_reached' => true,
            'routes_registered' => true,
            'service_class_loaded' => true,
            'service_class' => self::class,
            'state_cache_key' => 'local:'.self::STATE_PATH,
            'current_state' => $state,
            'state_error' => $stateError,
            'can_query_candidates' => $canQuery,
            'candidate_query_error' => $candidateError,
            'first_candidate_preview' => $preview,
            'safety_flags' => ['read_only' => true, 'no_mutation' => true, 'no_ovoko_request' => true],
        ];
    }

    public function stop(array $input): array
    {
        if (($input['confirm'] ?? null) !== self::STOP_CONFIRM) return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $state = $this->readState();
        $state['status'] = 'stopped';
        $state['finished_at'] = now()->toISOString();
        $this->writeState($state);
        return ['ok' => true] + $this->withComputed($state);
    }

    public function runNextBatch(array $input): array
    {
        if (($input['confirm'] ?? null) !== self::BATCH_CONFIRM) return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $state = $this->readState();
        if (($state['status'] ?? 'idle') !== 'running') return ['ok' => false, 'reason' => 'runner_not_running'] + $this->withComputed($state);

        $ids = array_values(array_diff($state['candidate_ids'] ?? [], $state['processed_ids'] ?? []));
        $batch = array_slice($ids, 0, (int) ($state['batch_size'] ?? 10));
        $results = [];
        foreach ($batch as $id) {
            try {
                $result = $this->processOne((int) $id, (string) ($state['mode'] ?? 'dry_run'));
            } catch (\Throwable $e) {
                $result = ['part_id' => (int) $id, 'action' => 'failed', 'error' => $e->getMessage()];
            }
            $results[] = $result;
            $state['processed_ids'][] = (int) $id;
            if (($result['action'] ?? null) === 'reset') $state['reset_ids'][] = (int) $id;
            elseif (($result['action'] ?? null) === 'dry_run') $state['dry_run_ids'][] = (int) $id;
            elseif (($result['action'] ?? null) === 'failed') $state['failed_ids'][] = ['part_id' => (int) $id, 'error' => $result['error'] ?? 'unknown'];
            else $state['skipped_ids'][] = ['part_id' => (int) $id, 'reason' => $result['reason'] ?? 'skipped'];
        }
        $state['last_batch_results'] = $results;
        $state['marker_position'] = count(array_unique($state['processed_ids'] ?? []));
        if ($state['marker_position'] >= count($state['candidate_ids'] ?? [])) {
            $state['status'] = 'completed';
            $state['finished_at'] = now()->toISOString();
        }
        $this->writeState($state);
        return ['ok' => true] + $this->withComputed($state);
    }

    private function processOne(int $partId, string $mode): array
    {
        $part = Part::query()->with(['marketplaceListings' => fn ($q) => $q->where('marketplace', 'ovoko')->orderByDesc('id')])->find($partId);
        if (! $part) return ['part_id' => $partId, 'action' => 'skipped', 'reason' => 'part_not_found'];
        $listing = $part->marketplaceListings->first();
        $strict = $this->strictCheck($part, $listing);
        if (! $strict['ok']) return ['part_id' => $partId, 'marketplace_listing_id' => $listing?->id, 'action' => 'skipped', 'reason' => $strict['reason']];
        $payload = $this->resultPayload($part, $listing);
        if ($mode === 'dry_run') return $payload + ['action' => 'dry_run', 'no_mutation' => true];
        DB::transaction(function () use ($part): void {
            MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', 'ovoko')->orderBy('id')->each(function (MarketplaceListing $listing): void {
                $raw = is_array($listing->raw_payload) ? $listing->raw_payload : [];
                Arr::set($raw, 'metadata.ovoko_part_mapping_reset_for_recreate', true);
                Arr::set($raw, 'metadata.reset_marker', 'ovoko_recreate_numeric_id_bridge_v5');
                Arr::set($raw, 'metadata.runner_marker', self::MARKER);
                Arr::set($raw, 'metadata.reset_at', now()->toISOString());
                Arr::set($raw, 'metadata.previous_sku', $listing->sku);
                Arr::set($raw, 'metadata.previous_external_offer_id', $listing->external_offer_id);
                Arr::set($raw, 'metadata.previous_external_listing_id', $listing->external_listing_id);
                Arr::set($raw, 'metadata.previous_external_inventory_id', $listing->external_inventory_id);
                Arr::set($raw, 'metadata.previous_url', $listing->url);
                Arr::set($raw, 'metadata.previous_ovoko_part_id', data_get($raw, 'ovoko_part_id') ?: data_get($raw, 'metadata.ovoko_part_id') ?: $listing->external_offer_id ?: $listing->external_listing_id);
                Arr::forget($raw, ['external_id', 'sku', 'external_inventory_id', 'external_offer_id', 'external_listing_id', 'ovoko_part_id', 'marketplace_external_id', 'listing_id', 'id_bridge', 'part_id', 'metadata.ovoko_part_id']);
                $listing->fill(['sku' => null, 'external_offer_id' => null, 'external_listing_id' => null, 'external_inventory_id' => null, 'url' => null, 'status' => 'unlinked', 'sync_status' => 'stale', 'match_status' => 'unmatched', 'raw_payload' => $raw, 'last_error' => null])->save();
            });
        });
        return $payload + ['action' => 'reset', 'no_ovoko_request' => true];
    }

    public function candidateIds(): array
    {
        if (! Schema::hasTable('parts') || ! Schema::hasTable('marketplace_listings')) return [];

        return Part::query()
            ->with(['marketplaceListings' => fn ($q) => $q->where('marketplace', 'ovoko')->orderByDesc('id')])
            ->whereHas('marketplaceListings', fn ($q) => $q->where('marketplace', 'ovoko')->whereNotNull('url')->where('url', '!=', ''))
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Part $p) => $this->strictCheck($p, $p->marketplaceListings->first())['ok'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function strictCheck(Part $part, ?MarketplaceListing $listing): array
    {
        if (! $listing || $listing->marketplace !== 'ovoko') return ['ok' => false, 'reason' => 'marketplace_listing_is_not_ovoko'];
        if (! $this->hasActiveMapping($listing)) return ['ok' => false, 'reason' => 'missing_active_ovoko_mapping_or_link'];
        if (! $this->identityLooksLikeGpsGmail($part, $listing)) return ['ok' => false, 'reason' => 'not_gps_gmail_identity'];
        if ($this->positivePrice($part->price) || $this->positivePrice($part->ovoko_price) || $this->positivePrice($listing->price)) return ['ok' => false, 'reason' => 'has_price_or_ovoko_price'];
        if (! (bool) $part->needs_listing) return ['ok' => false, 'reason' => 'not_to_publish_queue'];
        if ((bool) $part->is_visible_storefront || in_array((string) $part->status, ['ready', 'published'], true)) return ['ok' => false, 'reason' => 'is_in_parts_menu'];
        if (strcasecmp((string) ($listing->status ?: $part->status), 'published') === 0 || strcasecmp((string) $part->status, 'published') === 0) return ['ok' => false, 'reason' => 'published'];
        if (strcasecmp((string) ($listing->status ?: $part->status), 'imported') !== 0) return ['ok' => false, 'reason' => 'status_not_imported'];
        if ($this->hasActiveLiveSignal($part, $listing)) return ['ok' => false, 'reason' => 'reset_risk_level_not_low'];
        return ['ok' => true, 'reason' => null];
    }

    private function resultPayload(Part $part, MarketplaceListing $listing): array
    {
        return ['part_id' => $part->id, 'marketplace_listing_id' => $listing->id, 'sku' => $part->sku, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id, 'external_inventory_id' => $listing->external_inventory_id, 'url' => $listing->url, 'previous_values_target_metadata' => ['previous_sku' => $listing->sku, 'previous_external_offer_id' => $listing->external_offer_id, 'previous_external_listing_id' => $listing->external_listing_id, 'previous_external_inventory_id' => $listing->external_inventory_id, 'previous_url' => $listing->url]];
    }

    private function emptyState(): array
    {
        return ['ok' => true, 'marker' => self::MARKER, 'mode' => 'dry_run', 'status' => 'idle', 'started_at' => null, 'finished_at' => null, 'batch_size' => 10, 'delay_seconds' => 2, 'total_candidates_at_start' => 0, 'candidate_ids' => [], 'processed_ids' => [], 'reset_ids' => [], 'dry_run_ids' => [], 'skipped_ids' => [], 'failed_ids' => [], 'last_batch_results' => [], 'marker_position' => 0];
    }
    private function readState(): array { $decoded = Storage::disk('local')->exists(self::STATE_PATH) ? json_decode(Storage::disk('local')->get(self::STATE_PATH), true) : []; return array_merge($this->emptyState(), is_array($decoded) ? $decoded : []); }
    private function writeState(array $state): void { $json = json_encode($this->jsonSafe($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); Storage::disk('local')->put(self::STATE_PATH, $json); }
    private function jsonSafe(array $state): array { return json_decode(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR); }
    private function exceptionResult(\Throwable $e, string $phase): array { Log::error('Ovoko part mapping reset runner failed', ['marker' => self::MARKER, 'phase' => $phase, 'exception' => $e]); return ['ok' => false, 'phase' => $phase, 'error_class' => $e::class, 'message' => $e->getMessage()]; }
    private function withComputed(array $state): array { $processed = count(array_unique($state['processed_ids'] ?? [])); $total = (int) ($state['total_candidates_at_start'] ?? count($state['candidate_ids'] ?? [])); return $state + ['total_candidates' => $total, 'processed' => $processed, 'reset_count' => count($state['reset_ids'] ?? []), 'dry_run_count' => count($state['dry_run_ids'] ?? []), 'skipped_count' => count($state['skipped_ids'] ?? []), 'failed_count' => count($state['failed_ids'] ?? []), 'remaining' => max(0, $total - $processed)]; }
    private function hasActiveMapping(MarketplaceListing $l): bool { return (filled($l->external_offer_id) || filled($l->external_listing_id) || filled($l->external_inventory_id) || filled($l->url) || filled($l->sku)) && ! in_array((string) $l->status, ['unlinked', 'archived', 'deleted', 'ended', 'UNLINKED', 'ARCHIVED', 'DELETED', 'ENDED'], true); }
    private function identityLooksLikeGpsGmail(Part $p, ?MarketplaceListing $l): bool { foreach ([$p->sku, $p->part_number, $l?->sku, $l?->external_offer_id, $l?->external_inventory_id] as $v) if (preg_match('/^GPS-GMAIL-/i', (string) $v) === 1) return true; return false; }
    private function positivePrice(mixed $price): bool { return is_numeric($price) && (float) $price > 0; }
    private function hasActiveLiveSignal(Part $p, MarketplaceListing $l): bool { return in_array((string) $p->status, ['ready', 'published'], true) || in_array((string) $l->status, ['published', 'active', 'live', 'publication_pending', 'PUBLISHED', 'ACTIVE'], true) || in_array((string) $l->sync_status, ['published', 'active', 'live', 'PUBLISHED', 'ACTIVE'], true) || in_array((string) $l->last_api_status, ['published', 'active', 'live', 'PUBLISHED', 'ACTIVE'], true); }
}

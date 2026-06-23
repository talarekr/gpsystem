<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class OvokoStockReconciliationController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const SOURCE = 'ovoko_stock_reconciliation';
    private const REASON = 'missing_in_ovoko_active_stock';
    private const ENDPOINT_PATH = '/v2/get/parts';
    private const SNAPSHOT_CACHE_PREFIX = 'ovoko_stock_reconciliation_snapshot:';
    private const RUN_CACHE_PREFIX = 'ovoko_stock_reconciliation_run:';
    private const SNAPSHOT_TTL_HOURS = 3;

    public function dryRun(Request $request): JsonResponse
    {
        return response()->json($this->reconcile($request, false));
    }

    public function dryRunAll(Request $request): JsonResponse
    {
        return response()->json($this->reconcileAll($request));
    }

    public function prepareSnapshot(Request $request): JsonResponse
    {
        return response()->json($this->prepareOvokoSnapshot($request));
    }

    public function dryRunBatch(Request $request): JsonResponse
    {
        return response()->json($this->reconcileSnapshotBatch($request));
    }

    public function dryRunRange(Request $request): JsonResponse
    {
        return response()->json($this->reconcileSnapshotRange($request));
    }

    public function snapshotStep(Request $request): JsonResponse
    {
        return response()->json($this->runSnapshotStep($request));
    }

    public function reconciliationStep(Request $request): JsonResponse
    {
        return response()->json($this->runReconciliationStep($request));
    }

    public function runStatus(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $snapshotId = (string) $request->query('snapshot_id', '');
        $runId = (string) $request->query('run_id', '');

        return response()->json([
            'ok' => true,
            'dry_run' => true,
            'snapshot_id' => $snapshotId,
            'run_id' => $runId,
            'snapshot' => $snapshotId !== '' ? Cache::get($this->snapshotCacheKey($snapshotId)) : null,
            'run' => $runId !== '' ? Cache::get($this->runCacheKey($runId)) : null,
            'blockers' => [],
            'warnings' => ['read_only_status_no_local_or_marketplace_writes'],
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        if (! $request->boolean('confirm', false)) {
            $result = $this->reconcile($request, false);
            $result['blockers'][] = 'confirm_1_required_for_local_update';
            $result['dry_run'] = true;
            return response()->json($result, 422);
        }

        return response()->json($this->reconcile($request, true));
    }

    public function check(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $query = Part::query()->where('needs_review', true);
        $sample = (clone $query)->with(['marketplaceListings'])->limit(25)->get()->map(fn (Part $part): array => $this->partSample($part))->all();
        $storefrontCount = Part::query()->where('needs_review', true)->storefrontVisible()->count();

        return response()->json([
            'ok' => true,
            'needs_review_count' => (clone $query)->count(),
            'visible_in_storefront_count' => $storefrontCount,
            'admin_needs_review_count' => (clone $query)->count(),
            'sample' => $sample,
            'blockers' => $storefrontCount > 0 ? ['needs_review_visible_in_storefront'] : [],
            'warnings' => ['diagnostic_only_no_marketplace_writes'],
        ]);
    }

    private function reconcile(Request $request, bool $write): array
    {
        if (! $this->validToken($request)) return ['ok' => false, 'blockers' => ['invalid_token'], 'warnings' => []];

        $sampleLimit = max(1, min(200, (int) $request->query('sample_limit', 50)));
        $warnings = ['read_only_ovoko_api_no_marketplace_writes'];
        $blockers = [];
        $candidates = $this->candidateQuery($request)->get();
        $localCheckedIds = $candidates->flatMap(fn (Part $part) => $this->listingIds($this->ovokoListing($part)))->unique()->values()->all();
        $ovoko = $this->fetchOvokoActiveIds($request, $localCheckedIds);
        if (! ($ovoko['ok'] ?? false)) $blockers[] = $ovoko['error'] ?? 'ovoko_api_failed';
        if (! ($ovoko['last_page_reached'] ?? false)) $blockers[] = 'ovoko_active_list_incomplete_cannot_mark_missing';
        if (($ovoko['selected_id_field'] ?? null) === null) $blockers[] = 'ovoko_id_field_not_detected';

        $activeIds = $ovoko['active_ids'] ?? [];
        $ovokoComplete = (bool) ($ovoko['last_page_reached'] ?? false);
        $matched = [];
        $missing = [];
        $conflicts = [];
        $alreadyNeedsReview = 0;

        foreach ($candidates as $part) {
            $listing = $this->ovokoListing($part);
            $ids = $this->listingIds($listing);
            if ((bool) $part->needs_review) $alreadyNeedsReview++;
            if ($ids === []) {
                $conflicts[] = $this->partSample($part) + ['conflict' => 'missing_local_ovoko_external_id'];
                continue;
            }
            if (array_intersect($ids, $activeIds) !== []) {
                $matched[] = $this->partSample($part);
            } else {
                $missing[] = $this->partSample($part) + ['checked_ovoko_ids' => $ids];
            }
        }

        if ($candidates->count() > 0 && count($activeIds) > 0 && count($matched) === 0) {
            $blockers[] = 'zero_matches_between_local_and_ovoko_check_id_mapping_before_confirm';
        }
        if (($ovoko['mapping_confident'] ?? false) === false) {
            $blockers[] = 'ovoko_id_mapping_not_confident';
        }
        $blockers = array_values(array_unique($blockers));

        $updated = 0;
        if ($write && $blockers === []) {
            $runAt = now();
            foreach ($missing as $row) {
                $part = Part::query()->find($row['part_id']);
                if (! $part) continue;
                $part->update([
                    'needs_review' => true,
                    'review_reason' => self::REASON,
                    'review_source' => self::SOURCE,
                    'review_detected_at' => $runAt,
                    'review_metadata' => [
                        'ovoko_external_ids' => $row['checked_ovoko_ids'] ?? [],
                        'local_quantity' => $part->quantity,
                        'local_status' => $part->status,
                        'run_at' => $runAt->toISOString(),
                        'note' => 'No active record was found in Ovoko read-only API response.',
                    ],
                ]);
                $updated++;
            }
        }

        return [
            'ok' => $blockers === [],
            'dry_run' => ! $write,
            'local_update_only' => $write,
            'ovoko_api_total_count' => $ovoko['total_count'] ?? null,
            'ovoko_pages_fetched' => $ovoko['pages_fetched'] ?? 0,
            'ovoko_limit_per_page' => $ovoko['limit_per_page'] ?? null,
            'ovoko_last_page_reached' => $ovoko['last_page_reached'] ?? false,
            'ovoko_has_more' => $ovoko['has_more'] ?? null,
            'ovoko_max_pages' => $ovoko['max_pages'] ?? null,
            'ovoko_fetch_all_requested' => $ovoko['fetch_all_ovoko'] ?? false,
            'ovoko_active_ids_count' => count($activeIds),
            'ovoko_active_ids_min_max_sample' => $this->idMinMaxSample($activeIds),
            'ovoko_selected_id_field' => $ovoko['selected_id_field'] ?? null,
            'ovoko_detected_id_fields' => $ovoko['detected_id_fields'] ?? [],
            'sample_ovoko_active_raw' => array_slice($ovoko['raw_items'] ?? [], 0, $sampleLimit),
            'sample_ovoko_active_ids' => array_slice($activeIds, 0, $sampleLimit),
            'local_checked_ovoko_ids_sample' => array_slice($localCheckedIds, 0, $sampleLimit),
            'local_candidate_parts_count' => $candidates->count(),
            'matched_active_ovoko_count' => count($matched),
            'partial_missing_in_fetched_ovoko_count' => $ovokoComplete ? null : count($missing),
            'missing_in_ovoko_active_count' => $ovokoComplete ? count($missing) : null,
            'would_mark_needs_review_count' => $blockers === [] ? count(array_filter($missing, fn ($row) => ! ($row['needs_review'] ?? false))) : null,
            'marked_needs_review_count' => $updated,
            'already_needs_review_count' => $alreadyNeedsReview,
            'conflict_count' => count($conflicts),
            'sample_would_mark_needs_review' => array_slice($missing, 0, $sampleLimit),
            'sample_matched' => array_slice($matched, 0, $sampleLimit),
            'sample_conflicts' => array_slice($conflicts, 0, $sampleLimit),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }


    private function reconcileAll(Request $request): array
    {
        if (! $this->validToken($request)) return ['ok' => false, 'dry_run' => true, 'local_update_only' => false, 'blockers' => ['invalid_token'], 'warnings' => []];

        $sampleLimit = max(1, min(200, (int) $request->query('sample_limit', 50)));
        $localLimit = max(1, min(1000, (int) $request->query('local_limit', 100)));
        $maxLocalPages = max(1, min(10000, (int) $request->query('max_local_pages', 500)));
        $warnings = ['read_only_dry_run_all_no_local_or_marketplace_writes'];
        $blockers = [];

        $ovokoRequest = $request->duplicate($request->query(), null);
        $ovokoRequest->query->set('fetch_all_ovoko', $request->query('fetch_all_ovoko', '1'));
        $ovokoRequest->query->set('ovoko_limit', $request->query('ovoko_limit', 100));
        $ovokoRequest->query->set('ovoko_page', 1);
        $ovokoRequest->query->set('ovoko_max_pages', $request->query('max_ovoko_pages', $request->query('ovoko_max_pages', 200)));
        $ovoko = $this->fetchOvokoActiveIds($ovokoRequest, []);

        if (! ($ovoko['ok'] ?? false)) $blockers[] = $ovoko['error'] ?? 'ovoko_api_failed';
        if (! ($ovoko['last_page_reached'] ?? false)) $blockers[] = 'ovoko_active_list_incomplete_cannot_mark_missing';
        if (($ovoko['selected_id_field'] ?? null) === null || ($ovoko['mapping_confident'] ?? false) === false) $blockers[] = 'ovoko_id_mapping_not_confident';

        $activeIds = $ovoko['active_ids'] ?? [];
        $activeIdSet = array_flip(array_map('strval', $activeIds));
        $localPagesFetched = 0;
        $localLastPageReached = false;
        $localHasMore = false;
        $localCandidateCount = 0;
        $matched = [];
        $missing = [];
        $conflicts = [];
        $alreadyNeedsReview = 0;

        for ($page = 1; $page <= $maxLocalPages; $page++) {
            $batch = $this->candidateQueryForPage($request, $page, $localLimit)->get();
            $count = $batch->count();
            if ($count === 0) {
                $localLastPageReached = true;
                $localHasMore = false;
                break;
            }

            $localPagesFetched++;
            $localCandidateCount += $count;
            foreach ($batch as $part) {
                $listing = $this->ovokoListing($part);
                $ids = $this->listingIds($listing);
                if ((bool) $part->needs_review) $alreadyNeedsReview++;
                if ($ids === []) {
                    $conflicts[] = $this->partSample($part) + ['conflict' => 'missing_local_ovoko_external_id'];
                    continue;
                }
                if (array_intersect_key(array_flip($ids), $activeIdSet) !== []) {
                    $matched[] = $this->partSample($part);
                } else {
                    $missing[] = $this->partSample($part) + ['checked_ovoko_ids' => $ids];
                }
            }

            if ($count < $localLimit) {
                $localLastPageReached = true;
                $localHasMore = false;
                break;
            }

            if ($page === $maxLocalPages) {
                $localHasMore = true;
                $blockers[] = 'local_candidate_scan_incomplete';
            }
        }

        if ($localCandidateCount > 0 && count($activeIds) > 0 && count($matched) === 0) {
            $blockers[] = 'zero_matches_between_local_and_ovoko_check_id_mapping_before_confirm';
        }
        $blockers = array_values(array_unique($blockers));
        $localComplete = $localLastPageReached && ! $localHasMore;
        $completeForFinalMissing = $localComplete && (bool) ($ovoko['last_page_reached'] ?? false);

        return [
            'ok' => $blockers === [],
            'dry_run' => true,
            'local_update_only' => false,
            'ovoko_api_total_count' => $ovoko['total_count'] ?? null,
            'ovoko_pages_fetched' => $ovoko['pages_fetched'] ?? 0,
            'ovoko_limit_per_page' => $ovoko['limit_per_page'] ?? null,
            'ovoko_last_page_reached' => $ovoko['last_page_reached'] ?? false,
            'ovoko_has_more' => $ovoko['has_more'] ?? null,
            'ovoko_max_pages' => $ovoko['max_pages'] ?? null,
            'ovoko_active_ids_count' => count($activeIds),
            'ovoko_selected_id_field' => $ovoko['selected_id_field'] ?? null,
            'ovoko_detected_id_fields' => $ovoko['detected_id_fields'] ?? [],
            'local_pages_fetched' => $localPagesFetched,
            'local_limit_per_page' => $localLimit,
            'local_last_page_reached' => $localLastPageReached,
            'local_has_more' => $localHasMore,
            'local_candidate_parts_count' => $localCandidateCount,
            'matched_active_ovoko_count' => count($matched),
            'partial_missing_in_scanned_local_count' => $completeForFinalMissing ? null : count($missing),
            'missing_in_ovoko_active_count' => $completeForFinalMissing ? count($missing) : null,
            'would_mark_needs_review_count' => ($completeForFinalMissing && $blockers === []) ? count(array_filter($missing, fn ($row) => ! ($row['needs_review'] ?? false))) : null,
            'already_needs_review_count' => $alreadyNeedsReview,
            'conflict_count' => count($conflicts),
            'sample_would_mark_needs_review' => array_slice($missing, 0, $sampleLimit),
            'sample_matched' => array_slice($matched, 0, $sampleLimit),
            'sample_conflicts' => array_slice($conflicts, 0, $sampleLimit),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function prepareOvokoSnapshot(Request $request): array
    {
        if (! $this->validToken($request)) return ['ok' => false, 'dry_run' => true, 'blockers' => ['invalid_token'], 'warnings' => []];

        $warnings = ['read_only_snapshot_no_local_or_marketplace_writes'];
        $blockers = [];
        $ovokoRequest = $request->duplicate($request->query(), null);
        $ovokoRequest->query->set('fetch_all_ovoko', '1');
        $ovokoRequest->query->set('ovoko_limit', $request->query('ovoko_limit', 100));
        $ovokoRequest->query->set('ovoko_page', 1);
        $ovokoRequest->query->set('ovoko_max_pages', $request->query('max_ovoko_pages', $request->query('ovoko_max_pages', 200)));
        $ovoko = $this->fetchOvokoActiveIds($ovokoRequest, []);

        if (! ($ovoko['ok'] ?? false)) $blockers[] = $ovoko['error'] ?? 'ovoko_api_failed';
        if (! ($ovoko['last_page_reached'] ?? false)) $blockers[] = 'ovoko_active_list_incomplete_cannot_mark_missing';
        if (($ovoko['selected_id_field'] ?? null) === null || ($ovoko['mapping_confident'] ?? false) === false) $blockers[] = 'ovoko_id_mapping_not_confident';
        $blockers = array_values(array_unique($blockers));

        $snapshotId = null;
        $activeIds = $ovoko['active_ids'] ?? [];
        if ($blockers === []) {
            $createdAt = now();
            $expiresAt = $createdAt->copy()->addHours(self::SNAPSHOT_TTL_HOURS);
            $snapshotId = (string) Str::uuid();
            Cache::put($this->snapshotCacheKey($snapshotId), [
                'snapshot_id' => $snapshotId,
                'active_ids' => array_values(array_unique(array_map('strval', $activeIds))),
                'created_at' => $createdAt->toISOString(),
                'expires_at' => $expiresAt->toISOString(),
                'ovoko_api_total_count' => $ovoko['total_count'] ?? null,
                'ovoko_pages_fetched' => $ovoko['pages_fetched'] ?? 0,
                'ovoko_last_page_reached' => $ovoko['last_page_reached'] ?? false,
                'ovoko_has_more' => $ovoko['has_more'] ?? null,
                'ovoko_selected_id_field' => $ovoko['selected_id_field'] ?? null,
                'ovoko_detected_id_fields' => $ovoko['detected_id_fields'] ?? [],
            ], $expiresAt);
        }

        return [
            'ok' => $blockers === [],
            'dry_run' => true,
            'snapshot_id' => $snapshotId,
            'ovoko_api_total_count' => $ovoko['total_count'] ?? null,
            'ovoko_pages_fetched' => $ovoko['pages_fetched'] ?? 0,
            'ovoko_last_page_reached' => $ovoko['last_page_reached'] ?? false,
            'ovoko_has_more' => $ovoko['has_more'] ?? null,
            'ovoko_active_ids_count' => count($activeIds),
            'ovoko_selected_id_field' => $ovoko['selected_id_field'] ?? null,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function reconcileSnapshotBatch(Request $request): array
    {
        if (! $this->validToken($request)) return ['ok' => false, 'dry_run' => true, 'blockers' => ['invalid_token'], 'warnings' => []];

        $snapshot = $this->loadSnapshot((string) $request->query('snapshot_id', ''));
        if ($snapshot['blockers'] !== []) {
            return ['ok' => false, 'dry_run' => true, 'snapshot_id' => (string) $request->query('snapshot_id', ''), 'blockers' => $snapshot['blockers'], 'warnings' => ['read_only_batch_no_local_or_marketplace_writes']];
        }

        $page = max(1, (int) $request->query('page', 1));
        $localLimit = max(1, min(1000, (int) $request->query('local_limit', 100)));
        $sampleLimit = max(1, min(200, (int) $request->query('sample_limit', 50)));

        return $this->scanSnapshotLocalPage($request, $snapshot['data'], $page, $localLimit, $sampleLimit);
    }

    private function reconcileSnapshotRange(Request $request): array
    {
        if (! $this->validToken($request)) return ['ok' => false, 'dry_run' => true, 'blockers' => ['invalid_token'], 'warnings' => []];

        $snapshot = $this->loadSnapshot((string) $request->query('snapshot_id', ''));
        if ($snapshot['blockers'] !== []) {
            return ['ok' => false, 'dry_run' => true, 'snapshot_id' => (string) $request->query('snapshot_id', ''), 'blockers' => $snapshot['blockers'], 'warnings' => ['read_only_range_no_local_or_marketplace_writes']];
        }

        $fromPage = max(1, (int) $request->query('from_page', 1));
        $toPage = max($fromPage, (int) $request->query('to_page', $fromPage));
        $toPage = min($toPage, $fromPage + 19);
        $localLimit = max(1, min(1000, (int) $request->query('local_limit', 100)));
        $sampleLimit = max(1, min(200, (int) $request->query('sample_limit', 100)));
        $totals = ['local_candidate_parts_count' => 0, 'matched_active_ovoko_count' => 0, 'missing_in_ovoko_active_count' => 0, 'would_mark_needs_review_count' => 0, 'already_needs_review_count' => 0, 'conflict_count' => 0];
        $samples = ['sample_would_mark_needs_review' => [], 'sample_matched' => [], 'sample_conflicts' => []];
        $hasMore = false;
        $nextPage = null;

        for ($page = $fromPage; $page <= $toPage; $page++) {
            $batch = $this->scanSnapshotLocalPage($request, $snapshot['data'], $page, $localLimit, $sampleLimit);
            foreach ($totals as $key => $_) $totals[$key] += (int) ($batch[$key] ?? 0);
            foreach ($samples as $key => $_) $samples[$key] = array_slice(array_merge($samples[$key], $batch[$key] ?? []), 0, $sampleLimit);
            $hasMore = (bool) ($batch['has_more_local'] ?? false);
            $nextPage = $batch['next_page'] ?? null;
            if (! $hasMore) break;
        }

        return array_merge(['ok' => true, 'dry_run' => true, 'snapshot_id' => $snapshot['data']['snapshot_id'], 'from_page' => $fromPage, 'to_page' => $toPage, 'local_limit' => $localLimit], $totals, $samples, ['has_more_local' => $hasMore, 'next_page' => $nextPage, 'blockers' => [], 'warnings' => ['read_only_range_no_local_or_marketplace_writes']]);
    }

    private function runSnapshotStep(Request $request): array
    {
        if (! $this->validToken($request)) return ['ok' => false, 'dry_run' => true, 'blockers' => ['invalid_token'], 'warnings' => []];

        $limit = max(1, min(100, (int) $request->query('limit', 100)));
        $maxPages = max(1, min(200, (int) $request->query('max_pages', 200)));
        $snapshotId = (string) $request->query('snapshot_id', '');
        $now = now();
        $expiresAt = $now->copy()->addHours(self::SNAPSHOT_TTL_HOURS);
        $snapshot = $snapshotId !== '' ? Cache::get($this->snapshotCacheKey($snapshotId)) : null;

        if (! is_array($snapshot)) {
            $snapshotId = $snapshotId !== '' ? $snapshotId : (string) Str::uuid();
            $snapshot = [
                'snapshot_id' => $snapshotId,
                'active_ids' => [],
                'pages_fetched' => 0,
                'next_page' => 1,
                'ovoko_api_total_count' => null,
                'ovoko_active_ids_count' => 0,
                'snapshot_complete' => false,
                'ovoko_last_page_reached' => false,
                'ovoko_has_more' => true,
                'started_at' => $now->toISOString(),
                'updated_at' => $now->toISOString(),
                'expires_at' => $expiresAt->toISOString(),
                'ovoko_selected_id_field' => 'id',
                'ovoko_detected_id_fields' => ['id' => 0],
            ];
        }

        $page = max(1, (int) $request->query('page', $snapshot['next_page'] ?? 1));
        $ovoko = $this->fetchOvokoPartsPage($page, $limit);
        if (! ($ovoko['ok'] ?? false)) {
            return ['ok' => false, 'dry_run' => true, 'snapshot_id' => $snapshotId, 'page_fetched' => $page, 'limit' => $limit, 'blockers' => [$ovoko['error'] ?? 'ovoko_api_failed'], 'warnings' => ['read_only_snapshot_step_no_local_or_marketplace_writes']];
        }

        $activeIds = array_values(array_unique(array_merge(array_map('strval', $snapshot['active_ids'] ?? []), $ovoko['active_ids'])));
        $pagesFetched = (int) ($snapshot['pages_fetched'] ?? 0) + 1;
        $hasMore = (bool) ($ovoko['has_more'] ?? false);
        $maxPagesReached = $pagesFetched >= $maxPages;
        $complete = ! $hasMore;
        $nextPage = ($complete || $maxPagesReached) ? null : $page + 1;
        $snapshot = array_merge($snapshot, [
            'active_ids' => $activeIds,
            'pages_fetched' => $pagesFetched,
            'next_page' => $nextPage,
            'ovoko_api_total_count' => $ovoko['total_count'],
            'ovoko_active_ids_count' => count($activeIds),
            'snapshot_complete' => $complete,
            'ovoko_last_page_reached' => ! $hasMore,
            'ovoko_has_more' => $hasMore,
            'updated_at' => $now->toISOString(),
            'expires_at' => $snapshot['expires_at'] ?? $expiresAt->toISOString(),
            'ovoko_detected_id_fields' => ['id' => count($activeIds)],
        ]);
        Cache::put($this->snapshotCacheKey($snapshotId), $snapshot, \Illuminate\Support\Carbon::parse($snapshot['expires_at']));

        return ['ok' => true, 'dry_run' => true, 'snapshot_id' => $snapshotId, 'page_fetched' => $page, 'limit' => $limit, 'pages_fetched_total' => $pagesFetched, 'ovoko_api_total_count' => $snapshot['ovoko_api_total_count'], 'ovoko_active_ids_count' => count($activeIds), 'snapshot_complete' => $complete, 'next_page' => $nextPage, 'ovoko_has_more' => $hasMore, 'blockers' => [], 'warnings' => ['read_only_snapshot_step_no_local_or_marketplace_writes']];
    }

    private function runReconciliationStep(Request $request): array
    {
        if (! $this->validToken($request)) return ['ok' => false, 'dry_run' => true, 'blockers' => ['invalid_token'], 'warnings' => []];

        $snapshotId = (string) $request->query('snapshot_id', '');
        $snapshot = $this->loadStepSnapshot($snapshotId);
        if ($snapshot['blockers'] !== []) return ['ok' => false, 'dry_run' => true, 'snapshot_id' => $snapshotId, 'blockers' => $snapshot['blockers'], 'warnings' => ['read_only_reconciliation_step_no_local_or_marketplace_writes']];

        $runId = (string) $request->query('run_id', '');
        $run = $runId !== '' ? Cache::get($this->runCacheKey($runId)) : null;
        if (! is_array($run)) {
            $runId = $runId !== '' ? $runId : (string) Str::uuid();
            $run = ['run_id' => $runId, 'snapshot_id' => $snapshotId, 'local_pages_fetched' => 0, 'local_candidate_parts_count' => 0, 'matched_active_ovoko_count' => 0, 'missing_in_ovoko_active_count' => 0, 'would_mark_needs_review_count' => 0, 'already_needs_review_count' => 0, 'conflict_count' => 0, 'sample_would_mark_needs_review' => [], 'sample_matched' => [], 'sample_conflicts' => [], 'next_page' => 1, 'local_has_more' => true, 'run_complete' => false, 'started_at' => now()->toISOString()];
        }

        $page = max(1, (int) $request->query('page', $run['next_page'] ?? 1));
        $localLimit = max(1, min(100, (int) $request->query('local_limit', 100)));
        $sampleLimit = max(1, min(200, (int) $request->query('sample_limit', 50)));
        $maxLocalPages = max(1, min(500, (int) $request->query('max_local_pages', 500)));
        $batch = $this->scanSnapshotLocalPage($request, $snapshot['data'], $page, $localLimit, $sampleLimit);
        foreach (['local_candidate_parts_count', 'matched_active_ovoko_count', 'missing_in_ovoko_active_count', 'would_mark_needs_review_count', 'already_needs_review_count', 'conflict_count'] as $key) $run[$key] += (int) ($batch[$key] ?? 0);
        foreach (['sample_would_mark_needs_review', 'sample_matched', 'sample_conflicts'] as $key) $run[$key] = array_slice(array_merge($run[$key], $batch[$key] ?? []), 0, $sampleLimit);
        $run['local_pages_fetched']++;
        $run['local_has_more'] = (bool) ($batch['has_more_local'] ?? false) && $run['local_pages_fetched'] < $maxLocalPages;
        $run['next_page'] = $run['local_has_more'] ? $page + 1 : null;
        $run['run_complete'] = ! $run['local_has_more'];
        $run['updated_at'] = now()->toISOString();
        Cache::put($this->runCacheKey($runId), $run, now()->addHours(self::SNAPSHOT_TTL_HOURS));

        return ['ok' => true, 'dry_run' => true, 'snapshot_id' => $snapshotId, 'run_id' => $runId, 'page_fetched' => $page, 'local_limit' => $localLimit, 'local_candidate_parts_count_total' => $run['local_candidate_parts_count'], 'matched_active_ovoko_count_total' => $run['matched_active_ovoko_count'], 'missing_in_ovoko_active_count_total' => $run['missing_in_ovoko_active_count'], 'would_mark_needs_review_count_total' => $run['would_mark_needs_review_count'], 'already_needs_review_count_total' => $run['already_needs_review_count'], 'conflict_count_total' => $run['conflict_count'], 'batch' => Arr::only($batch, ['local_candidate_parts_count', 'matched_active_ovoko_count', 'missing_in_ovoko_active_count', 'would_mark_needs_review_count']), 'sample_would_mark_needs_review' => $run['sample_would_mark_needs_review'], 'sample_matched' => $run['sample_matched'], 'sample_conflicts' => $run['sample_conflicts'], 'run_complete' => $run['run_complete'], 'next_page' => $run['next_page'], 'local_has_more' => $run['local_has_more'], 'blockers' => [], 'warnings' => ['read_only_reconciliation_step_no_local_or_marketplace_writes']];
    }

    private function scanSnapshotLocalPage(Request $request, array $snapshot, int $page, int $localLimit, int $sampleLimit): array
    {
        $activeIdSet = array_flip(array_map('strval', $snapshot['active_ids'] ?? []));
        $batch = $this->candidateQueryForPage($request, $page, $localLimit)->get();
        $matched = [];
        $missing = [];
        $conflicts = [];
        $alreadyNeedsReview = 0;
        foreach ($batch as $part) {
            $listing = $this->ovokoListing($part);
            $ids = $this->listingIds($listing);
            if ((bool) $part->needs_review) $alreadyNeedsReview++;
            if ($ids === []) {
                $conflicts[] = $this->partSample($part) + ['conflict' => 'missing_local_ovoko_external_id'];
            } elseif (array_intersect_key(array_flip($ids), $activeIdSet) !== []) {
                $matched[] = $this->partSample($part);
            } else {
                $missing[] = $this->partSample($part) + ['checked_ovoko_ids' => $ids];
            }
        }
        $count = $batch->count();
        $hasMore = $count >= $localLimit && $this->candidateQueryForPage($request, $page + 1, 1)->exists();

        return [
            'ok' => true,
            'dry_run' => true,
            'snapshot_id' => $snapshot['snapshot_id'],
            'page' => $page,
            'local_limit' => $localLimit,
            'local_candidate_parts_count' => $count,
            'matched_active_ovoko_count' => count($matched),
            'missing_in_ovoko_active_count' => count($missing),
            'would_mark_needs_review_count' => count(array_filter($missing, fn ($row) => ! ($row['needs_review'] ?? false))),
            'already_needs_review_count' => $alreadyNeedsReview,
            'conflict_count' => count($conflicts),
            'sample_would_mark_needs_review' => array_slice($missing, 0, $sampleLimit),
            'sample_matched' => array_slice($matched, 0, $sampleLimit),
            'sample_conflicts' => array_slice($conflicts, 0, $sampleLimit),
            'has_more_local' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
            'blockers' => [],
            'warnings' => ['read_only_batch_no_local_or_marketplace_writes'],
        ];
    }

    private function loadSnapshot(string $snapshotId): array
    {
        if ($snapshotId === '') return ['data' => null, 'blockers' => ['ovoko_snapshot_not_found']];
        $snapshot = Cache::get($this->snapshotCacheKey($snapshotId));
        if (! is_array($snapshot)) return ['data' => null, 'blockers' => ['ovoko_snapshot_not_found']];
        if (empty($snapshot['expires_at']) || now()->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse($snapshot['expires_at']))) return ['data' => null, 'blockers' => ['ovoko_snapshot_expired']];
        if (($snapshot['ovoko_last_page_reached'] ?? false) !== true) return ['data' => null, 'blockers' => ['ovoko_active_list_incomplete_cannot_mark_missing']];
        if (empty($snapshot['ovoko_selected_id_field'])) return ['data' => null, 'blockers' => ['ovoko_id_mapping_not_confident']];
        return ['data' => $snapshot, 'blockers' => []];
    }

    private function loadStepSnapshot(string $snapshotId): array
    {
        if ($snapshotId === '') return ['data' => null, 'blockers' => ['ovoko_snapshot_not_found']];
        $snapshot = Cache::get($this->snapshotCacheKey($snapshotId));
        if (! is_array($snapshot)) return ['data' => null, 'blockers' => ['ovoko_snapshot_not_found']];
        if (empty($snapshot['expires_at']) || now()->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse($snapshot['expires_at']))) return ['data' => null, 'blockers' => ['ovoko_snapshot_expired']];
        if (($snapshot['snapshot_complete'] ?? $snapshot['ovoko_last_page_reached'] ?? false) !== true) return ['data' => null, 'blockers' => ['ovoko_snapshot_incomplete']];
        return ['data' => $snapshot, 'blockers' => []];
    }

    private function snapshotCacheKey(string $snapshotId): string
    {
        return self::SNAPSHOT_CACHE_PREFIX.$snapshotId;
    }

    private function runCacheKey(string $runId): string
    {
        return self::RUN_CACHE_PREFIX.$runId;
    }

    private function fetchOvokoPartsPage(int $page, int $limit): array
    {
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first() : null;
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        if (! $account || blank($account->api_base_url) || blank($credentials['username'] ?? null) || blank($credentials['password'] ?? null) || blank($credentials['user_token'] ?? null)) {
            return ['ok' => false, 'error' => 'ovoko_api_credentials_missing', 'active_ids' => [], 'total_count' => null, 'has_more' => true];
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post(rtrim((string) $account->api_base_url, '/').self::ENDPOINT_PATH.'?limit='.$limit.'&page='.$page, Arr::only($credentials, ['username', 'password', 'user_token']));
            $payload = $response->json() ?: [];
            if (! $response->successful() || (string) ($payload['status_code'] ?? '') !== 'R200') {
                return ['ok' => false, 'error' => 'ovoko_api_business_error', 'active_ids' => [], 'total_count' => null, 'has_more' => true];
            }
            $items = $payload['parts'] ?? $payload['data']['parts'] ?? $payload['data'] ?? [];
            $items = is_array($items) ? array_values($items) : [];
            $ids = collect($items)->map(fn ($item) => (array) $item)->pluck('id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
            $totalCount = $payload['total_count'] ?? $payload['data']['total_count'] ?? $payload['pagination']['total_count'] ?? $payload['pagination']['total'] ?? $payload['total'] ?? null;
            $payloadHasMore = $payload['has_more'] ?? $payload['data']['has_more'] ?? $payload['pagination']['has_more'] ?? null;
            $hasMore = is_bool($payloadHasMore) ? $payloadHasMore : (count($items) >= $limit && ($totalCount === null || ($page * $limit) < (int) $totalCount));
            return ['ok' => true, 'active_ids' => $ids, 'total_count' => $totalCount, 'has_more' => $hasMore];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'ovoko_api_exception', 'active_ids' => [], 'total_count' => null, 'has_more' => true];
        }
    }

    private function fetchOvokoActiveIds(Request $request, array $localCheckedIds): array
    {
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first() : null;
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        if (! $account || blank($account->api_base_url) || blank($credentials['username'] ?? null) || blank($credentials['password'] ?? null) || blank($credentials['user_token'] ?? null)) {
            return ['ok' => false, 'error' => 'ovoko_api_credentials_missing', 'active_ids' => [], 'last_page_reached' => false, 'pages_fetched' => 0];
        }

        $limit = max(1, min(100, (int) $request->query('ovoko_limit', $request->query('limit', 100))));
        $page = max(1, (int) $request->query('ovoko_page', 1));
        $fetchAllOvoko = $request->boolean('fetch_all_ovoko', false);
        $requestedMaxPages = (int) $request->query('max_pages', $request->query('ovoko_max_pages', $fetchAllOvoko ? 200 : 50));
        $maxPages = max(1, min(200, $requestedMaxPages));
        $rawItems = [];
        $detectedFields = [];
        $totalCount = null;
        $lastPageReached = false;
        $hasMore = null;
        $pagesFetched = 0;

        try {
            for ($i = 0; $i < $maxPages; $i++, $page++) {
                $response = Http::asForm()->acceptJson()->timeout(30)->post(rtrim((string) $account->api_base_url, '/').self::ENDPOINT_PATH.'?limit='.$limit.'&page='.$page, Arr::only($credentials, ['username', 'password', 'user_token']));
                $payload = $response->json() ?: [];
                $pagesFetched++;
                if (! $response->successful() || ($payload['status_code'] ?? null) !== 'R200') {
                    return ['ok' => false, 'error' => 'ovoko_api_business_error', 'active_ids' => [], 'raw_items' => $rawItems, 'detected_id_fields' => $detectedFields, 'last_page_reached' => false, 'pages_fetched' => $pagesFetched, 'limit_per_page' => $limit, 'total_count' => $totalCount, 'has_more' => true];
                }

                $totalCount ??= $payload['total_count'] ?? $payload['data']['total_count'] ?? $payload['pagination']['total_count'] ?? $payload['pagination']['total'] ?? $payload['total'] ?? null;
                $items = $payload['parts'] ?? $payload['data']['parts'] ?? $payload['data'] ?? [];
                $items = is_array($items) ? array_values($items) : [];
                foreach ($items as $item) {
                    $item = (array) $item;
                    $rawItems[] = $item;
                    foreach ($this->ovokoCandidateIdFields($item) as $field => $value) {
                        $detectedFields[$field] = ($detectedFields[$field] ?? 0) + 1;
                    }
                }

                $payloadHasMore = $payload['has_more'] ?? $payload['data']['has_more'] ?? $payload['pagination']['has_more'] ?? null;
                $hasMore = is_bool($payloadHasMore) ? $payloadHasMore : (count($items) >= $limit);
                if ($hasMore === false || count($items) < $limit || ($totalCount !== null && count($rawItems) >= (int) $totalCount)) {
                    $lastPageReached = true;
                    $hasMore = false;
                    break;
                }
            }

            $selectedField = $this->selectOvokoIdField($rawItems, $localCheckedIds, (string) $request->query('ovoko_id_field', 'auto'));
            $ids = $selectedField ? collect($rawItems)->map(fn ($item) => $item[$selectedField] ?? null)->filter()->map(fn ($v) => (string) $v)->unique()->values()->all() : [];
            return [
                'ok' => true,
                'active_ids' => $ids,
                'raw_items' => $rawItems,
                'detected_id_fields' => $detectedFields,
                'selected_id_field' => $selectedField,
                'mapping_confident' => $selectedField !== null && (count($localCheckedIds) === 0 || count(array_intersect($ids, $localCheckedIds)) > 0),
                'total_count' => $totalCount,
                'pages_fetched' => $pagesFetched,
                'limit_per_page' => $limit,
                'last_page_reached' => $lastPageReached,
                'has_more' => $hasMore,
                'max_pages' => $maxPages,
                'fetch_all_ovoko' => $fetchAllOvoko,
            ];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'ovoko_api_exception', 'active_ids' => [], 'raw_items' => $rawItems, 'detected_id_fields' => $detectedFields, 'last_page_reached' => false, 'pages_fetched' => $pagesFetched, 'limit_per_page' => $limit, 'total_count' => $totalCount, 'has_more' => true];
        }
    }

    private function candidateQuery(Request $request): Builder
    {
        return $this->candidateQueryForPage($request, max(1, (int) $request->query('page', 1)), max(1, min(1000, (int) $request->query('limit', 100))));
    }

    private function candidateQueryForPage(Request $request, int $page, int $limit): Builder
    {
        $query = Part::query()->with(['marketplaceListings'])->where('quantity', '>', 0)->where(fn (Builder $q) => $q->whereNull('status')->orWhereNotIn('status', ['sold', 'archived']));
        if (! $request->boolean('include_needs_listing', false)) $query->where(fn (Builder $q) => $q->where('needs_listing', false)->orWhereNull('needs_listing'));
        if (! $request->boolean('include_archived', false)) $query->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', 'archived'));
        if ($request->boolean('only_with_ovoko_mapping', true)) $query->whereHas('marketplaceListings', fn (Builder $q) => $q->where('marketplace', 'ovoko'));
        return $query->orderBy('id')->forPage($page, $limit);
    }

    private function ovokoListing(Part $part): ?MarketplaceListing { return $part->marketplaceListings->firstWhere('marketplace', 'ovoko'); }
    private function listingIds(?MarketplaceListing $listing): array { return collect([$listing?->external_offer_id, $listing?->external_listing_id, $listing?->external_inventory_id, $listing?->sku])->filter()->map(fn ($v) => (string) $v)->unique()->values()->all(); }
    private function ovokoCandidateIdFields(array $item): array
    {
        $fields = ['id', 'part_id', 'external_id', 'car_part_id', 'code', 'external_listing_id', 'sku'];
        return collect($fields)->filter(fn (string $field): bool => filled($item[$field] ?? null))->mapWithKeys(fn (string $field): array => [$field => (string) $item[$field]])->all();
    }

    private function selectOvokoIdField(array $items, array $localCheckedIds, string $requestedField): ?string
    {
        $available = collect($items)->flatMap(fn (array $item): array => array_keys($this->ovokoCandidateIdFields($item)))->unique()->values();
        if ($requestedField !== 'auto') {
            return $available->contains($requestedField) ? $requestedField : null;
        }

        $localSet = array_flip(array_map('strval', $localCheckedIds));
        $scores = [];
        foreach ($available as $field) {
            $values = collect($items)->map(fn (array $item) => isset($item[$field]) ? (string) $item[$field] : null)->filter()->unique()->values()->all();
            $scores[$field] = count(array_intersect_key(array_flip($values), $localSet));
        }
        arsort($scores);
        $bestField = array_key_first($scores);
        if ($bestField !== null && ($scores[$bestField] ?? 0) > 0) return $bestField;

        return $available->contains('id') ? 'id' : ($available->first() ?: null);
    }

    private function idMinMaxSample(array $ids): array
    {
        $numeric = collect($ids)->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id);
        return [
            'min' => $numeric->isNotEmpty() ? $numeric->min() : null,
            'max' => $numeric->isNotEmpty() ? $numeric->max() : null,
            'sample' => array_slice($ids, 0, 20),
        ];
    }

    private function partSample(Part $part): array { $listing = $this->ovokoListing($part); return ['part_id' => $part->id, 'name' => $part->name, 'part_number' => $part->part_number, 'sku' => $part->sku, 'quantity' => $part->quantity, 'status' => $part->status, 'needs_review' => (bool) $part->needs_review, 'review_reason' => $part->review_reason, 'ovoko_listing_id' => $listing?->id, 'ovoko_external_id' => $listing?->external_offer_id ?? $listing?->external_listing_id]; }
    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidTokenResponse(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
}

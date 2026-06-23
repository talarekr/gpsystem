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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OvokoStockReconciliationController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const SOURCE = 'ovoko_stock_reconciliation';
    private const REASON = 'missing_in_ovoko_active_stock';
    private const ENDPOINT_PATH = '/v2/get/parts';

    public function dryRun(Request $request): JsonResponse
    {
        return response()->json($this->reconcile($request, false));
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
        $query = Part::query()->with(['marketplaceListings'])->where('quantity', '>', 0)->where(fn (Builder $q) => $q->whereNull('status')->orWhereNotIn('status', ['sold', 'archived']));
        if (! $request->boolean('include_needs_listing', false)) $query->where(fn (Builder $q) => $q->where('needs_listing', false)->orWhereNull('needs_listing'));
        if (! $request->boolean('include_archived', false)) $query->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', 'archived'));
        if ($request->boolean('only_with_ovoko_mapping', true)) $query->whereHas('marketplaceListings', fn (Builder $q) => $q->where('marketplace', 'ovoko'));
        return $query->orderBy('id')->forPage(max(1, (int) $request->query('page', 1)), max(1, min(1000, (int) $request->query('limit', 100))));
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

<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OvokoStockSyncController extends Controller
{
    private const CONFIRM = 'ovoko-stock-sync';
    private const ENDPOINT_PATH = '/get/part';
    private const SHAPE_KEYS = ['data', 'item', 'part', 'parts', 'items', 'result', 'list'];
    private const ACTION = 'ovoko_stock_pull';

    public function dryRun(Request $request): JsonResponse
    {
        return response()->json($this->buildPlan($request, false));
    }

    public function apply(Request $request): JsonResponse
    {
        if ($request->query('confirm') !== self::CONFIRM) {
            return response()->json([
                'ok' => false,
                'dry_run' => false,
                'marketplace_write' => false,
                'blockers' => ['confirm_ovoko_stock_sync_required'],
                'warnings' => ['no_changes_applied'],
            ], 422);
        }

        if (! $request->filled('part_id')) {
            return response()->json([
                'ok' => false,
                'dry_run' => false,
                'marketplace_write' => false,
                'blockers' => ['part_id_required_for_apply'],
                'warnings' => ['single_part_apply_only_no_mass_apply'],
            ], 422);
        }

        $plan = $this->buildPlan($request, true);
        $item = $plan['items'][0] ?? null;
        if (($plan['blockers'] ?? []) !== [] || ! $item || ($item['blockers'] ?? []) !== []) {
            $this->writeLog($item, false, 'blocked', $plan['blockers'] ?? ($item['blockers'] ?? []));
            return response()->json($plan, 422);
        }

        if (($item['action'] ?? null) === 'already_correct') {
            $this->writeLog($item, false, 'already_correct', []);
            return response()->json($plan + ['applied_count' => 0]);
        }

        $applied = false;
        DB::transaction(function () use ($item, &$applied): void {
            $part = Part::query()->lockForUpdate()->find($item['part_id']);
            if (! $part || (bool) $part->needs_listing) {
                return;
            }

            $part->forceFill($item['planned_local_state'])->save();
            $applied = true;
        });

        $this->writeLog($item, $applied, $applied ? 'success' : 'blocked', $applied ? [] : ['part_unavailable_or_guard_failed_during_apply']);

        return response()->json($plan + ['applied_count' => $applied ? 1 : 0]);
    }

    private function buildPlan(Request $request, bool $apply): array
    {
        $limit = max(1, min(50, (int) $request->query('limit', 1)));
        $partIds = $request->filled('part_id') ? [(int) $request->query('part_id')] : Part::query()
            ->where('needs_listing', false)
            ->when($request->boolean('only_active'), fn ($q) => $q->storefrontVisible())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $items = array_map(fn (int $partId): array => $this->planPart($partId), $partIds);
        $blockers = array_values(array_unique(Arr::flatten(array_map(fn ($i) => $i['blockers'] ?? [], $items))));

        return [
            'ok' => $blockers === [],
            'dry_run' => ! $apply,
            'marketplace_write' => false,
            'endpoints' => ['dry_run' => '/admin/tools/ovoko-stock-sync-dry-run', 'apply' => '/admin/tools/ovoko-stock-sync-apply?confirm='.self::CONFIRM],
            'apply_requires_part_id' => true,
            'updated_fields' => ['quantity', 'status', 'is_visible_storefront'],
            'items' => $items,
            'counters' => $this->availabilityCounters($items),
            'blockers' => $blockers,
            'warnings' => ['local_stock_only_no_price_no_publish_no_relist_no_end_no_marketplace_writes'],
        ];
    }

    public function planPart(int $partId): array
    {
        $part = Part::query()->find($partId);
        if (! $part) {
            return $this->blockedItem($partId, null, null, null, ['missing_local_part']);
        }

        $local = $this->localState($part);
        if ((bool) $part->needs_listing) {
            return $this->blockedItem($partId, null, null, $local, ['skipped_do_wystawienia']);
        }
        $mapping = $this->resolveOvokoMapping($part);
        if (! ($mapping['ok'] ?? false)) {
            return $this->blockedItem($partId, null, null, $local, [$mapping['blocker'] ?? 'missing_ovoko_mapping']);
        }

        $ovokoId = (string) $mapping['ovoko_id'];
        $ovoko = $this->fetchOvokoPart($ovokoId);
        if (! ($ovoko['ok'] ?? false)) {
            return $this->blockedItem($partId, $ovokoId, $mapping['source'], $local, [$ovoko['blocker'] ?? 'ovoko_api_failed'], $ovoko);
        }

        $stock = $this->mapOvokoAvailability($ovoko['part'] ?? []);
        if (! ($stock['ok'] ?? false)) {
            return [
                'part_id' => $partId,
                'ovoko_id' => $ovokoId,
                'ovoko_mapping_source' => $mapping['source'],
                'local' => $local,
                'ovoko' => $this->ovokoStockSummary($ovoko, $stock),
                'blockers' => [$stock['blocker'] ?? 'ovoko_stock_not_unambiguous'],
                'available_on_ovoko' => $stock['available_on_ovoko'] ?? null,
                'ovoko_availability_source' => $stock['ovoko_availability_source'] ?? null,
                'ovoko_status_raw' => $stock['ovoko_status_raw'] ?? null,
                'ovoko_status_meaning' => $stock['ovoko_status_meaning'] ?? 'availability_unknown',
                'reserved_user' => data_get($ovoko, 'part.reserved_user'),
                'reserved_date' => data_get($ovoko, 'part.reserved_date'),
                'local_availability' => $local['availability'] ?? null,
                'recommended_local_availability' => null,
                'action' => 'blocked',
            ];
        }

        $recommended = ($stock['available_on_ovoko'] ?? null) === true ? 'for_sale' : 'sold';
        $planned = $recommended === 'for_sale'
            ? ['quantity' => 1, 'status' => 'ready', 'is_visible_storefront' => true, 'sold_at' => null]
            : ['quantity' => 0, 'status' => 'sold', 'is_visible_storefront' => false];
        $localAvailability = $this->localAvailability($part);
        $action = $localAvailability === $recommended ? 'already_correct' : ($recommended === 'for_sale' ? 'should_mark_for_sale' : 'should_mark_sold');

        return [
            'part_id' => $partId,
            'ovoko_id' => $ovokoId,
            'ovoko_mapping_source' => $mapping['source'],
            'local' => $local,
            'ovoko' => $this->ovokoStockSummary($ovoko, $stock),
            'available_on_ovoko' => $stock['available_on_ovoko'] ?? null,
            'ovoko_availability_source' => $stock['ovoko_availability_source'] ?? null,
            'ovoko_status_raw' => $stock['ovoko_status_raw'] ?? null,
            'ovoko_status_meaning' => $stock['ovoko_status_meaning'] ?? null,
            'reserved_user' => data_get($ovoko, 'part.reserved_user'),
            'reserved_date' => data_get($ovoko, 'part.reserved_date'),
            'local_availability' => $localAvailability,
            'recommended_local_availability' => $recommended,
            'planned_local_state' => $planned,
            'action' => $action,
            'blockers' => [],
        ];
    }

    private function blockedItem(int $partId, ?string $ovokoId, ?string $mappingSource, ?array $local, array $blockers, ?array $ovoko = null): array
    {
        return [
            'part_id' => $partId,
            'ovoko_id' => $ovokoId,
            'ovoko_mapping_source' => $mappingSource,
            'local' => $local,
            'ovoko' => $ovoko,
            'available_on_ovoko' => null,
            'ovoko_availability_source' => null,
            'ovoko_status_raw' => null,
            'ovoko_status_meaning' => 'availability_unknown',
            'reserved_user' => null,
            'reserved_date' => null,
            'local_availability' => $local['availability'] ?? null,
            'recommended_local_availability' => null,
            'blockers' => $blockers,
            'action' => 'blocked',
        ];
    }

    private function availabilityCounters(array $items): array
    {
        $counters = array_fill_keys(['available_on_ovoko_count','not_available_on_ovoko_count','availability_unknown_count','local_for_sale_count','local_sold_count','already_correct_count','should_mark_for_sale_count','should_mark_sold_count','blocked_count'], 0);
        foreach ($items as $item) {
            $available = $item['available_on_ovoko'] ?? null;
            if ($available === true) $counters['available_on_ovoko_count']++;
            elseif ($available === false) $counters['not_available_on_ovoko_count']++;
            else $counters['availability_unknown_count']++;
            if (($item['local_availability'] ?? null) === 'for_sale') $counters['local_for_sale_count']++;
            elseif (($item['local_availability'] ?? null) === 'sold') $counters['local_sold_count']++;
            if (isset($counters[($item['action'] ?? '').'_count'])) $counters[($item['action'] ?? '').'_count']++;
        }
        return $counters;
    }

    /** @return array{ok: bool, ovoko_id?: string, source?: string, blocker?: string} */
    private function resolveOvokoMapping(Part $part): array
    {
        $listingMapping = $this->resolveFromMarketplaceListings($part);
        if (($listingMapping['ovoko_id'] ?? null) !== null) {
            return ['ok' => true, 'ovoko_id' => (string) $listingMapping['ovoko_id'], 'source' => (string) $listingMapping['source']];
        }

        $legacyMapping = app(\App\Services\Marketplace\OvokoPartIdExtractor::class)->extractWithPath($part->legacy_payload);
        if (($legacyMapping['id'] ?? null) !== null) {
            return ['ok' => true, 'ovoko_id' => (string) $legacyMapping['id'], 'source' => 'parts.legacy_payload.'.($legacyMapping['path'] ?? 'ovoko_part_id')];
        }

        return ['ok' => false, 'blocker' => 'missing_ovoko_mapping'];
    }

    /** @return array{ovoko_id: ?string, source: ?string} */
    private function resolveFromMarketplaceListings(Part $part): array
    {
        if (! Schema::hasTable('marketplace_listings')) {
            return ['ovoko_id' => null, 'source' => null];
        }

        $columns = ['id', 'external_offer_id', 'external_listing_id', 'external_inventory_id', 'status', 'last_api_status', 'not_seen_in_active_api_at', 'raw_payload'];
        if (Schema::hasColumn('marketplace_listings', 'external_id')) {
            $columns[] = 'external_id';
        }

        $listings = MarketplaceListing::query()
            ->where('marketplace', 'ovoko')
            ->where('part_id', $part->id)
            ->orderByRaw("CASE WHEN status IN ('published','active','ACTIVE','live') AND (last_api_status IS NULL OR last_api_status NOT IN ('ended','inactive','deleted','archived','not_found')) AND not_seen_in_active_api_at IS NULL THEN 0 ELSE 1 END")
            ->latest('id')
            ->get($columns);

        foreach ($listings as $listing) {
            foreach ($this->listingIdCandidates($listing) as $candidate) {
                if ($candidate['ovoko_id'] !== null) {
                    return $candidate;
                }
            }
        }

        return ['ovoko_id' => null, 'source' => null];
    }

    /** @return array<int, array{ovoko_id: ?string, source: ?string}> */
    private function listingIdCandidates(MarketplaceListing $listing): array
    {
        $raw = is_array($listing->raw_payload) ? $listing->raw_payload : [];

        return [
            ['ovoko_id' => $this->blankId($listing->external_offer_id), 'source' => 'marketplace_listing.external_offer_id'],
            ['ovoko_id' => $this->blankId($listing->external_listing_id), 'source' => 'marketplace_listing.external_listing_id'],
            ['ovoko_id' => Schema::hasColumn('marketplace_listings', 'external_id') ? $this->blankId($listing->getAttribute('external_id')) : null, 'source' => 'marketplace_listing.external_id'],
            ['ovoko_id' => $this->blankId($raw['external_id'] ?? null), 'source' => 'marketplace_listing.raw_payload.external_id'],
            ['ovoko_id' => $this->blankId($raw['ovoko_part_id'] ?? null), 'source' => 'marketplace_listing.raw_payload.ovoko_part_id'],
            ['ovoko_id' => $this->blankId($raw['marketplace_external_id'] ?? null), 'source' => 'marketplace_listing.raw_payload.marketplace_external_id'],
            ['ovoko_id' => $this->blankId($raw['listing_id'] ?? null), 'source' => 'marketplace_listing.raw_payload.listing_id'],
            ['ovoko_id' => $this->blankId(data_get($raw, 'metadata.ovoko_part_id')), 'source' => 'marketplace_listing.raw_payload.metadata.ovoko_part_id'],
        ];
    }

    private function blankId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, 'GPSW-') ? null : $value;
    }

    private function fetchOvokoPart(string $id): array
    {
        $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
        if (! $account || ! $account->api_enabled || blank($account->api_base_url)) return ['ok' => false, 'blocker' => 'ovoko_api_not_configured'];
        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
        foreach (['username', 'password', 'user_token'] as $key) if (blank($credentials[$key] ?? null)) return ['ok' => false, 'blocker' => 'ovoko_api_credentials_missing'];

        $endpoint = rtrim((string) $account->api_base_url, '/').self::ENDPOINT_PATH.'/'.rawurlencode($id);
        $attempts = [[
            'name' => 'official_get_part_by_path_id',
            'method' => 'POST',
            'endpoint' => $endpoint,
            'query_params' => [],
            'body' => Arr::only($credentials, ['username', 'password', 'user_token']),
            'official_source' => 'RRR API docs: POST /get/part/{id}; id is a required URI value; request body contains only authentication form data.',
        ]];

        $lastShape = null;
        $candidateIds = [];
        $safeAttempts = [];

        try {
            foreach ($attempts as $attempt) {
                $response = Http::asForm()->acceptJson()->timeout(30)->post($attempt['endpoint'], $attempt['body']);
                $payload = $response->json();
                $payload = is_array($payload) ? $payload : [];
                $shape = $this->responseShape($payload, $response->status());
                $rows = $this->extractRows($payload);
                $attemptCandidateIds = $this->candidateIds($rows);
                $candidateIds = array_values(array_unique(array_merge($candidateIds, $attemptCandidateIds)));
                $filterApplied = in_array((string) $id, $attemptCandidateIds, true) && count(array_diff($attemptCandidateIds, [(string) $id])) === 0;
                $safeAttempts[] = [
                    'name' => $attempt['name'],
                    'method' => $attempt['method'],
                    'endpoint' => $attempt['endpoint'],
                    'query_params' => $attempt['query_params'],
                    'request_body_keys' => array_keys($attempt['body']),
                    'request_body_sample' => $this->sanitizeSample($attempt['body']),
                    'official_source' => $attempt['official_source'],
                    'http_status' => $response->status(),
                    'lookup_fields' => ['path.id'],
                    'filter_applied' => $filterApplied,
                    'response_shape' => $shape,
                    'candidate_ids' => $attemptCandidateIds,
                ];
                $lastShape = $shape;

                if (! $response->successful()) {
                    $blocker = in_array($response->status(), [401, 403], true) ? 'ovoko_api_auth_failed' : ($response->status() === 429 ? 'ovoko_api_rate_limited' : 'ovoko_api_failed');
                    return ['ok' => false, 'http_status' => $response->status(), 'blocker' => $blocker, 'ovoko_lookup_attempts' => $safeAttempts, 'ovoko_response_shape' => $lastShape, 'candidate_ids' => $candidateIds, 'filter_applied' => false];
                }

                $matches = array_values(array_filter($rows, fn (array $row): bool => (string) $this->extractOvokoId($row) === (string) $id));
                if ($matches !== [] && $filterApplied) {
                    return [
                        'ok' => true,
                        'http_status' => $response->status(),
                        'part' => $matches[0],
                        'matched_count' => count($matches),
                        'matched_in_attempt' => $attempt['name'],
                        'ovoko_lookup_attempts' => $safeAttempts,
                        'ovoko_response_shape' => $lastShape,
                        'candidate_ids' => $candidateIds,
                        'filter_applied' => true,
                    ];
                }

                if ($attemptCandidateIds !== [] && ! $filterApplied) {
                    return ['ok' => false, 'http_status' => $response->status(), 'blocker' => 'ovoko_lookup_filter_not_applied', 'ovoko_lookup_attempts' => $safeAttempts, 'ovoko_response_shape' => $lastShape, 'candidate_ids' => $candidateIds, 'filter_applied' => false];
                }
            }

            return ['ok' => false, 'http_status' => $safeAttempts[array_key_last($safeAttempts)]['http_status'] ?? null, 'blocker' => 'missing_ovoko_product', 'ovoko_lookup_attempts' => $safeAttempts, 'ovoko_response_shape' => $lastShape, 'candidate_ids' => $candidateIds, 'filter_applied' => false];
        } catch (Throwable) {
            return ['ok' => false, 'blocker' => 'ovoko_api_exception', 'ovoko_lookup_attempts' => $safeAttempts, 'ovoko_response_shape' => $lastShape, 'candidate_ids' => $candidateIds, 'filter_applied' => false];
        }
    }

    private function responseShape(array $payload, int $httpStatus): array
    {
        $rows = $this->extractRows($payload);

        return [
            'http_status' => $httpStatus,
            'top_level_keys' => array_slice(array_keys($payload), 0, 50),
            'has_wrappers' => array_reduce(self::SHAPE_KEYS, function (array $carry, string $key) use ($payload): array {
                $carry[$key] = array_key_exists($key, $payload);
                return $carry;
            }, []),
            'count' => count($rows),
            'candidate_ids' => $this->candidateIds($rows),
            'raw_sample' => $this->sanitizeSample($payload),
        ];
    }

    private function extractRows(array $payload): array
    {
        $rows = [];
        if (isset($payload['id']) || isset($payload['part_id']) || isset($payload['ovoko_id']) || isset($payload['rrr_id'])) {
            $rows[] = $payload;
        }

        foreach (self::SHAPE_KEYS as $key) {
            if (! array_key_exists($key, $payload)) continue;
            $value = $payload[$key];
            if (! is_array($value)) continue;
            if ($this->isAssoc($value)) {
                if (isset($value['id']) || isset($value['part_id']) || isset($value['ovoko_id']) || isset($value['rrr_id'])) {
                    $rows[] = $value;
                    continue;
                }
                foreach (self::SHAPE_KEYS as $nestedKey) {
                    if (isset($value[$nestedKey]) && is_array($value[$nestedKey])) {
                        foreach ($this->normalizeRows($value[$nestedKey]) as $row) $rows[] = $row;
                    }
                }
                continue;
            }
            foreach ($this->normalizeRows($value) as $row) $rows[] = $row;
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    private function normalizeRows(array $value): array
    {
        if ($this->isAssoc($value)) return [$value];

        $rows = [];
        foreach ($value as $item) {
            if (! is_array($item)) continue;
            if ($this->isAssoc($item)) {
                $rows[] = $item;
                continue;
            }
            foreach ($this->normalizeRows($item) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function candidateIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            $id = $this->extractOvokoId($row);
            if ($id !== null && $id !== '') $ids[] = (string) $id;
            if (count($ids) >= 20) break;
        }

        return array_values(array_unique($ids));
    }

    private function sanitizeSample(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) return '[truncated_depth]';
        if (is_array($value)) {
            $sample = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 12) {
                    $sample['__truncated__'] = true;
                    break;
                }
                $lowerKey = strtolower((string) $key);
                if (str_contains($lowerKey, 'token') || str_contains($lowerKey, 'password') || str_contains($lowerKey, 'secret') || str_contains($lowerKey, 'authorization')) {
                    $sample[$key] = '[redacted]';
                } else {
                    $sample[$key] = $this->sanitizeSample($item, $depth + 1);
                }
                $count++;
            }
            return $sample;
        }
        if (is_string($value)) return strlen($value) > 200 ? substr($value, 0, 200).'…[truncated]' : $value;
        return $value;
    }

    private function extractOvokoId(array $row): mixed { return $row['id'] ?? $row['part_id'] ?? $row['ovoko_id'] ?? $row['rrr_id'] ?? null; }

    private function extractQuantity(array $row): ?int
    {
        foreach (['quantity', 'stock', 'qty', 'amount', 'available_quantity'] as $field) {
            if (array_key_exists($field, $row) && is_numeric($row[$field])) return max(0, (int) $row[$field]);
        }
        return null;
    }


    private function ovokoStockSummary(array $ovoko, array $stock): array
    {
        return [
            'quantity' => $stock['quantity'] ?? null,
            'status' => data_get($ovoko, 'part.status'),
            'ovoko_stock_source' => $stock['ovoko_stock_source'] ?? null,
            'ovoko_status_raw' => $stock['ovoko_status_raw'] ?? data_get($ovoko, 'part.status'),
            'ovoko_status_meaning' => $stock['ovoko_status_meaning'] ?? null,
            'quantity_inferred' => (bool) ($stock['quantity_inferred'] ?? false),
            'availability_inferred' => (bool) ($stock['availability_inferred'] ?? false),
            'reserved_user' => data_get($ovoko, 'part.reserved_user'),
            'reserved_date' => data_get($ovoko, 'part.reserved_date'),
            'http_status' => $ovoko['http_status'] ?? null,
            'matched_in_attempt' => $ovoko['matched_in_attempt'] ?? null,
            'matched_count' => $ovoko['matched_count'] ?? null,
            'ovoko_lookup_attempts' => $ovoko['ovoko_lookup_attempts'] ?? [],
            'ovoko_response_shape' => $ovoko['ovoko_response_shape'] ?? null,
            'candidate_ids' => $ovoko['candidate_ids'] ?? [],
            'filter_applied' => $ovoko['filter_applied'] ?? false,
        ];
    }

    private function mapOvokoAvailability(array $row): array
    {
        $reserved = $this->isOvokoReserved($row);
        $statusRaw = array_key_exists('status', $row) ? trim((string) $row['status']) : null;

        if ($reserved === true) {
            return [
                'ok' => true,
                'available_on_ovoko' => false,
                'quantity' => 0,
                'ovoko_availability_source' => 'reserved_fields',
                'ovoko_stock_source' => 'reserved_fields',
                'ovoko_status_raw' => $statusRaw,
                'ovoko_status_meaning' => 'reserved_not_available',
                'quantity_inferred' => true,
                'availability_inferred' => true,
            ];
        }

        if ($statusRaw !== null && $statusRaw !== '') {
            if ($statusRaw === '0') {
                return [
                    'ok' => true,
                    'available_on_ovoko' => true,
                    'quantity' => 1,
                    'ovoko_availability_source' => 'status_0_without_reservation',
                    'ovoko_stock_source' => 'status_0_without_reservation',
                    'ovoko_status_raw' => $statusRaw,
                    'ovoko_status_meaning' => 'active_available',
                    'quantity_inferred' => true,
                    'availability_inferred' => true,
                ];
            }

            return [
                'ok' => false,
                'blocker' => 'availability_unknown_status_unmapped',
                'available_on_ovoko' => null,
                'ovoko_availability_source' => 'status',
                'ovoko_stock_source' => 'status',
                'ovoko_status_raw' => $statusRaw,
                'ovoko_status_meaning' => 'availability_unknown',
                'quantity_inferred' => false,
                'availability_inferred' => false,
            ];
        }

        $quantity = $this->extractQuantity($row);
        if ($quantity !== null) {
            return [
                'ok' => true,
                'available_on_ovoko' => $quantity > 0,
                'quantity' => $quantity > 0 ? 1 : 0,
                'ovoko_availability_source' => 'quantity_without_status',
                'ovoko_stock_source' => 'quantity_without_status',
                'ovoko_status_raw' => null,
                'ovoko_status_meaning' => $quantity > 0 ? 'quantity_positive_available' : 'quantity_zero_not_available',
                'quantity_inferred' => false,
                'availability_inferred' => false,
            ];
        }

        return ['ok' => false, 'blocker' => 'availability_unknown_missing_status', 'available_on_ovoko' => null, 'ovoko_status_meaning' => 'availability_unknown', 'quantity_inferred' => false, 'availability_inferred' => false];
    }

    private function isOvokoReserved(array $row): ?bool
    {
        $reservedUser = trim((string) ($row['reserved_user'] ?? ''));
        $reservedDate = trim((string) ($row['reserved_date'] ?? ''));
        if ($reservedUser !== '' && $reservedUser !== '0') return true;
        if ($reservedDate !== '' && ! in_array($reservedDate, ['0', '0000-00-00 00:00:00', 'null'], true)) return true;
        if (array_key_exists('reserved_user', $row) || array_key_exists('reserved_date', $row)) return false;
        return null;
    }

    private function localState(Part $part): array
    {
        return [
            'quantity' => (int) $part->quantity,
            'status' => $part->status,
            'status_raw' => $part->status,
            'ui_label' => $part->uiStatusLabel(),
            'is_visible_storefront' => (bool) $part->is_visible_storefront,
            'needs_listing' => (bool) $part->needs_listing,
            'sold_at' => $part->sold_at?->toISOString(),
            'sale_source' => $part->sale_source,
            'availability' => $this->localAvailability($part),
            'availability_source' => $part->localAvailabilitySourceForMarketplaceSync(),
        ];
    }

    private function localAvailability(Part $part): string
    {
        return $part->localAvailabilityForMarketplaceSync();
    }

    public function writeLog(?array $item, bool $applied, string $status, array $blockers, ?int $runId = null): void
    {
        MarketplaceSyncLog::query()->create([
            'marketplace' => 'ovoko',
            'part_id' => $item['part_id'] ?? null,
            'action' => self::ACTION,
            'status' => $status,
            'http_status' => data_get($item, 'ovoko.http_status'),
            'message' => $applied ? 'Local stock updated from Ovoko read API.' : 'No local stock change applied.',
            'external_id' => isset($item['ovoko_id']) ? (string) $item['ovoko_id'] : null,
            'payload' => [
                'marketplace_write' => false,
                'dry_run' => false,
                'part_id' => $item['part_id'] ?? null,
                'ovoko_id' => $item['ovoko_id'] ?? null,
                'previous_local_state' => $item['local'] ?? null,
                'fetched_ovoko_state' => $item['ovoko'] ?? null,
                'planned_local_state' => $item['planned_local_state'] ?? null,
                'applied_changes' => $applied ? $item['planned_local_state'] ?? [] : [],
                'blockers' => $blockers,
                'run_id' => $runId,
            ],
            'created_at' => now(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OvokoProductSyncController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const REQUIRED_FIELDS = [
        'part_number', 'name', 'description', 'price', 'currency', 'quantity', 'storage_location',
        'images', 'public_image_urls', 'ovoko_category_mapping', 'condition',
    ];

    public function dryRun(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $limit = max(1, min(250, (int) $request->query('limit', 50)));
        $page = max(1, (int) $request->query('page', 1));
        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 20)));
        $mode = (string) $request->query('mode', 'create_missing');
        $includeAlreadyListed = $request->boolean('include_already_listed', false);

        if ($mode !== 'create_missing') {
            return response()->json(['ok' => false, 'dry_run' => true, 'ovoko_write' => false, 'blockers' => ['unsupported_mode'], 'mode' => $mode], 422);
        }

        $summary = $this->emptyDryRunSummary($mode, $page, $limit);

        $parts = $this->partsQuery($request)
            ->forPage($page, $limit)
            ->get();

        foreach ($parts as $part) {
            $summary['local_candidate_parts_count']++;
            $analysis = $this->analysePart($part, $includeAlreadyListed);

            if ($analysis['already_has_ovoko_listing']) {
                $summary['already_has_ovoko_listing_count']++;
                $this->pushSample($summary['sample_already_listed'], $analysis['sample'], $sampleLimit);
            } else {
                $summary['missing_ovoko_listing_candidate_count']++;
            }

            if ($analysis['blockers'] !== []) {
                $summary['blocked_count']++;
                $this->pushSample($summary['sample_blocked'], $analysis['sample'], $sampleLimit);
                if ($analysis['already_has_ovoko_listing']) {
                    $this->pushSample($summary['sample_already_listed_blocked'], $analysis['sample'], $sampleLimit);
                } else {
                    $this->pushSample($summary['sample_missing_listing_blocked'], $analysis['sample'], $sampleLimit);
                    $this->pushSample($summary['sample_create_missing_blocked'], $analysis['sample'], $sampleLimit);
                }
                foreach ($analysis['blockers'] as $blocker) {
                    $summary['blockers'][$blocker] = ($summary['blockers'][$blocker] ?? 0) + 1;
                    $bucket = $analysis['already_has_ovoko_listing'] ? 'top_blockers_already_listed' : 'top_blockers_missing_listing';
                    $summary[$bucket][$blocker] = ($summary[$bucket][$blocker] ?? 0) + 1;
                }
            } elseif (! $analysis['already_has_ovoko_listing']) {
                $summary['would_create_ovoko_count']++;
                $this->pushSample($summary['sample_would_create'], $analysis['sample'], $sampleLimit);
                $this->pushSample($summary['sample_payloads'], $analysis['payload'], $sampleLimit);
            }

            if ($analysis['warnings'] !== []) {
                $summary['warning_count']++;
                foreach ($analysis['warnings'] as $warning) $summary['warnings'][$warning] = ($summary['warnings'][$warning] ?? 0) + 1;
            }
        }

        arsort($summary['top_blockers_already_listed']);
        arsort($summary['top_blockers_missing_listing']);
        ksort($summary['blockers']);
        ksort($summary['warnings']);

        return response()->json($summary);
    }

    public function readiness(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 20)));
        $result = ['ok' => true, 'ready_for_ovoko_create_count' => 0, 'already_has_ovoko_listing_count' => 0, 'missing_ovoko_listing_candidate_count' => 0, 'blocked_count' => 0, 'top_blockers' => [], 'top_blockers_already_listed' => [], 'top_blockers_missing_listing' => [], 'sample_ready' => [], 'sample_blocked' => [], 'sample_missing_listing_blocked' => [], 'sample_create_missing_blocked' => [], 'warnings' => ['dry_run_only_no_ovoko_or_other_marketplace_writes']];

        $this->partsQuery($request)->chunkById(200, function ($parts) use (&$result, $sampleLimit): void {
            foreach ($parts as $part) {
                $analysis = $this->analysePart($part, false);
                if ($analysis['already_has_ovoko_listing']) $result['already_has_ovoko_listing_count']++;
                else $result['missing_ovoko_listing_candidate_count']++;
                if ($analysis['blockers'] !== []) {
                    $result['blocked_count']++;
                    $this->pushSample($result['sample_blocked'], $analysis['sample'], $sampleLimit);
                    if (! $analysis['already_has_ovoko_listing']) {
                        $this->pushSample($result['sample_missing_listing_blocked'], $analysis['sample'], $sampleLimit);
                        $this->pushSample($result['sample_create_missing_blocked'], $analysis['sample'], $sampleLimit);
                    }
                    foreach ($analysis['blockers'] as $blocker) {
                        $result['top_blockers'][$blocker] = ($result['top_blockers'][$blocker] ?? 0) + 1;
                        $bucket = $analysis['already_has_ovoko_listing'] ? 'top_blockers_already_listed' : 'top_blockers_missing_listing';
                        $result[$bucket][$blocker] = ($result[$bucket][$blocker] ?? 0) + 1;
                    }
                } elseif (! $analysis['already_has_ovoko_listing']) {
                    $result['ready_for_ovoko_create_count']++;
                    $this->pushSample($result['sample_ready'], $analysis['sample'], $sampleLimit);
                }
            }
        });

        arsort($result['top_blockers']);
        arsort($result['top_blockers_already_listed']);
        arsort($result['top_blockers_missing_listing']);

        return response()->json($result);
    }


    public function fetchOvokoCategoryTreePreview(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 20)));
        $result = ['ok' => true, 'dry_run' => true, 'ovoko_write' => false, 'local_update' => false, 'ovoko_read_request' => true, 'endpoint' => 'https://api.rrr.lt/get/categories', 'category_count' => 0, 'level_counts' => [], 'sample_categories' => [], 'sample_level_3_categories' => [], 'id_map' => [], 'sample_full_pl_paths' => [], 'sample_errors' => [], 'warnings' => ['read_only_preview_no_ovoko_allegro_ebay_or_local_writes']];
        $tree = $this->fetchOvokoCategoryTree($result);
        $categories = $tree['categories'];
        $result['category_count'] = count($categories);
        foreach ($categories as $category) {
            $level = (string) ($category['level'] ?? 'unknown');
            $result['level_counts'][$level] = ($result['level_counts'][$level] ?? 0) + 1;
            $this->pushSample($result['sample_categories'], $category, $sampleLimit);
            if ((int) ($category['level'] ?? 0) === 3) $this->pushSample($result['sample_level_3_categories'], $category, $sampleLimit);
            if (count($result['id_map']) < $sampleLimit) $result['id_map'][(string) $category['id']] = ['pl' => $category['pl'] ?? null, 'en' => $category['en'] ?? null, 'parent_id' => $category['parent_id'] ?? null, 'level' => $category['level'] ?? null];
        }
        foreach ($categories as $category) {
            if ((int) ($category['level'] ?? 0) === 3) $this->pushSample($result['sample_full_pl_paths'], ['id' => (string) $category['id'], 'pl_path' => $this->ovokoCategoryPath($category, $tree['by_id'], 'pl')], $sampleLimit);
        }
        ksort($result['level_counts']);

        return response()->json($result);
    }


    public function dryRunOvokoCategoryMappingFromLinkedProducts(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $limit = max(1, min(100, (int) $request->query('limit', 100)));
        $page = max(1, (int) $request->query('page', 1));
        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 50)));
        $onlyMissing = $request->boolean('only_missing_ovoko_category_mapping', true);
        $includeAmbiguous = $request->boolean('include_ambiguous', true);

        $result = $this->emptyLinkedCategoryMappingResponse($page, $limit);
        $parts = $this->linkedOvokoPartsQuery($onlyMissing)->forPage($page, $limit)->get();

        $categoryTree = $this->fetchOvokoCategoryTree($result);
        $ovokoPartsById = $this->fetchOvokoPartsByLinkedIds($parts, $page, $limit, $result);
        $groups = [];

        foreach ($parts as $part) {
            $result['linked_products_checked']++;
            $listing = $this->ovokoListing($part);
            $ovokoId = $this->listingOvokoExternalId($listing);
            if (! $part->category_id || $this->isBezKategorii($part)) { $result['skipped_uncategorized_count']++; continue; }
            $ovoko = $ovokoId !== null ? ($ovokoPartsById[$ovokoId] ?? null) : null;
            $category = $ovoko ? $this->extractOvokoCategory($ovoko, $categoryTree, $result) : null;
            if (! $category || blank($category['ovoko_category_id'])) {
                $result['unmapped_or_missing_category_count']++;
                $this->pushSample($result['sample_products_without_ovoko_category'], $this->linkedProductSample($part, $ovokoId, $category), $sampleLimit);
                continue;
            }
            $result['linked_products_with_ovoko_category']++;
            $key = (string) $part->category_id;
            $groups[$key] ??= $this->localCategoryGroup($part);
            $catKey = (string) $category['ovoko_category_id'];
            $groups[$key]['observed_ovoko_categories'][$catKey] ??= $category + ['count' => 0, 'sample_part_ids' => [], 'sample_ovoko_part_ids' => []];
            $groups[$key]['observed_ovoko_categories'][$catKey]['count']++;
            $this->pushSample($groups[$key]['observed_ovoko_categories'][$catKey]['sample_part_ids'], ['part_id' => $part->id], $sampleLimit);
            $this->pushSample($groups[$key]['observed_ovoko_categories'][$catKey]['sample_ovoko_part_ids'], ['ovoko_part_id' => $ovokoId], $sampleLimit);
            $this->pushSample($groups[$key]['sample_parts'], $this->linkedProductSample($part, $ovokoId, $category), $sampleLimit);
        }

        foreach ($groups as $group) {
            $observed = array_values($group['observed_ovoko_categories']);
            $result['local_categories_observed_count']++;
            if (count($observed) === 1) {
                $cat = $observed[0];
                $result['suggested_mapping_count']++;
                $result['suggested_mappings'][] = [
                    'local_category_id' => $group['local_category_id'],
                    'local_category_name' => $group['local_category_name'],
                    'local_category_path' => $group['local_category_path'],
                    'ovoko_category_id' => $cat['ovoko_category_id'],
                    'ovoko_category_name' => $cat['ovoko_category_name'],
                    'ovoko_category_path' => $cat['ovoko_category_path'],
                    'evidence_count' => $cat['count'],
                    'sample_part_ids' => array_column($cat['sample_part_ids'], 'part_id'),
                    'sample_ovoko_part_ids' => array_column($cat['sample_ovoko_part_ids'], 'ovoko_part_id'),
                    'confidence' => 'high',
                    'match_type' => 'linked_products_consensus',
                ];
            } else {
                $result['ambiguous_mapping_count']++;
                if ($includeAmbiguous) $result['ambiguous_mappings'][] = [
                    'local_category_id' => $group['local_category_id'],
                    'local_category_path' => $group['local_category_path'],
                    'observed_ovoko_categories' => $observed,
                    'reason' => 'multiple_ovoko_categories_observed_for_one_local_category',
                    'sample_parts' => $group['sample_parts'],
                ];
            }
        }

        return response()->json($result);
    }

    public function previewOvokoCategoryFromLinkedProducts(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $localCategoryId = (int) $request->query('local_category_id');
        if ($localCategoryId < 1) return response()->json(['ok' => false, 'error_message' => 'local_category_id is required.'], 422);

        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 50)));
        $result = ['ok' => true, 'dry_run' => true, 'ovoko_write' => false, 'local_update' => false, 'ovoko_read_request' => true, 'local_category_id' => $localCategoryId, 'local_category_path' => null, 'linked_products_checked' => 0, 'observed_ovoko_categories' => [], 'suggested_mapping' => null, 'ambiguous' => false, 'sample_products' => [], 'sample_errors' => [], 'warnings' => ['read_only_preview_no_ovoko_allegro_ebay_or_local_writes']];
        $parts = $this->linkedOvokoPartsQuery(false)->where('category_id', $localCategoryId)->limit(100)->get();
        $ovokoPartsById = $this->fetchOvokoPartsByLinkedIds($parts, 1, 100, $result);
        $observed = [];
        foreach ($parts as $part) {
            $result['linked_products_checked']++;
            $result['local_category_path'] ??= $part->category?->category_path ?? $part->category?->name;
            $ovokoId = $this->listingOvokoExternalId($this->ovokoListing($part));
            $categoryTree = $categoryTree ?? $this->fetchOvokoCategoryTree($result);
            $category = $ovokoId ? $this->extractOvokoCategory($ovokoPartsById[$ovokoId] ?? [], $categoryTree, $result) : null;
            $this->pushSample($result['sample_products'], $this->linkedProductSample($part, $ovokoId, $category), $sampleLimit);
            if (! $category || blank($category['ovoko_category_id'])) continue;
            $key = (string) $category['ovoko_category_id'];
            $observed[$key] ??= $category + ['count' => 0, 'sample_part_ids' => [], 'sample_ovoko_part_ids' => []];
            $observed[$key]['count']++;
            $this->pushSample($observed[$key]['sample_part_ids'], ['part_id' => $part->id], $sampleLimit);
            $this->pushSample($observed[$key]['sample_ovoko_part_ids'], ['ovoko_part_id' => $ovokoId], $sampleLimit);
        }
        $result['observed_ovoko_categories'] = array_values($observed);
        $result['ambiguous'] = count($observed) > 1;
        if (count($observed) === 1) {
            $cat = array_values($observed)[0];
            $result['suggested_mapping'] = ['local_category_id' => $localCategoryId, 'local_category_path' => $result['local_category_path'], 'ovoko_category_id' => $cat['ovoko_category_id'], 'ovoko_category_name' => $cat['ovoko_category_name'], 'ovoko_category_path' => $cat['ovoko_category_path'], 'evidence_count' => $cat['count'], 'confidence' => 'high', 'match_type' => 'linked_products_consensus'];
        }
        return response()->json($result);
    }

    public function categoryDataSources(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 20)));
        $tables = $this->databaseTables();
        $interestingTables = collect($tables)
            ->filter(fn (string $table) => in_array($table, ['categories', 'part_categories', 'parts', 'marketplace_category_mappings', 'marketplace_listings'], true) || preg_match('/(category|ovoko|external|marketplace|import)/i', $table))
            ->values();
        $checkedTables = [];
        $candidateColumns = [];
        $possibleSources = [];

        foreach ($interestingTables as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table);
            $checkedTables[] = ['table' => $table, 'record_count' => $this->safeCount($table), 'columns' => $columns];
            foreach ($columns as $column) {
                if (! $this->looksLikeOvokoCategoryColumn($column)) continue;
                $nonEmpty = $this->nonEmptyCount($table, $column);
                $candidateColumns[] = ['table' => $table, 'column' => $column, 'non_empty_count' => $nonEmpty];
                if ($nonEmpty > 0) {
                    $possibleSources[] = ['table' => $table, 'column' => $column, 'non_empty_count' => $nonEmpty, 'samples' => $this->columnSamples($table, $column, $sampleLimit)];
                }
            }
        }

        $categoryTable = Schema::hasTable('categories') ? 'categories' : (Schema::hasTable('part_categories') ? 'part_categories' : null);
        $categoryRows = $categoryTable ? DB::table($categoryTable)->select($this->safeSelectColumns($categoryTable, ['id', 'name', 'category_path', 'slug', 'external_id', 'source_system']))->limit(500)->get() : collect();
        $ovokoRows = $this->ovokoCategoryLikeRows($interestingTables->all(), $sampleLimit);
        $nameMatches = $this->categoryNameMatches($categoryRows, $ovokoRows, $sampleLimit);

        return response()->json([
            'ok' => true,
            'dry_run' => true,
            'ovoko_write' => false,
            'local_update' => false,
            'checked_tables' => $checkedTables,
            'candidate_columns' => $candidateColumns,
            'possible_ovoko_category_sources' => $possibleSources,
            'existing_marketplace_category_mappings_summary' => $this->marketplaceMappingsSummary($sampleLimit),
            'categories_table_summary' => $this->tableSummary($categoryTable, ['id', 'name', 'category_path', 'slug', 'external_id', 'source_system'], $sampleLimit),
            'parts_table_category_summary' => $this->partsCategorySummary($sampleLimit, $nameMatches, $categoryTable),
            'possible_name_matches_summary' => ['local_categories_checked' => $categoryRows->count(), 'ovoko_like_records_checked' => count($ovokoRows), 'match_count' => count($nameMatches)],
            'sample_matches' => $nameMatches,
            'sample_unmatched_local_categories' => $this->unmatchedLocalCategorySamples($categoryRows, $nameMatches, $sampleLimit),
            'warnings' => ['read_only_discovery_only_no_ovoko_allegro_ebay_or_local_writes'],
        ]);
    }
    public function inspectOvokoCategoryLegacyPayloads(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 20)));
        $focusIds = collect(explode(',', (string) $request->query('focus_ids', '31,32,20')))
            ->map(fn (string $id) => (int) trim($id))->filter()->values()->all();
        $warnings = ['read_only_diagnostics_only_no_ovoko_allegro_ebay_or_local_writes'];

        if (! Schema::hasTable('part_categories')) {
            return response()->json([
                'ok' => true, 'dry_run' => true, 'ovoko_write' => false, 'local_update' => false,
                'categories_checked' => 0, 'legacy_payload_non_empty_count' => 0,
                'detected_legacy_payload_shapes' => [], 'possible_ovoko_keys' => [],
                'possible_ovoko_category_records_count' => 0,
                'sample_possible_ovoko_category_records' => [],
                'sample_current_missing_ovoko_mapping_with_legacy_payload' => [],
                'suggested_mapping_strategy' => [],
                'warnings' => array_merge($warnings, ['part_categories_table_missing']),
            ]);
        }

        $select = $this->safeSelectColumns('part_categories', ['id', 'name', 'category_path', 'external_id', 'legacy_payload']);
        $categoriesChecked = $this->safeCount('part_categories');
        $legacyNonEmptyCount = Schema::hasColumn('part_categories', 'legacy_payload') ? $this->nonEmptyCount('part_categories', 'legacy_payload') : 0;
        $shapes = [];
        $possibleKeys = [];
        $records = [];
        $strategy = [];

        if (! Schema::hasColumn('part_categories', 'legacy_payload')) {
            $warnings[] = 'part_categories_legacy_payload_column_missing';
        } else {
            DB::table('part_categories')->select($select)->whereNotNull('legacy_payload')->where('legacy_payload', '!=', '')->orderBy('id')->chunk(200, function ($rows) use (&$shapes, &$possibleKeys, &$records, &$strategy, $sampleLimit): void {
                foreach ($rows as $row) {
                    $category = (array) $row;
                    $payload = $this->decodeLegacyPayload($category['legacy_payload'] ?? null);
                    if (! is_array($payload)) continue;
                    $shape = implode(',', array_slice(array_keys($payload), 0, 20));
                    $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
                    foreach ($this->findOvokoLegacyPayloadCandidates($payload) as $candidate) {
                        $possibleKeys[$candidate['detected_key_path']] = ($possibleKeys[$candidate['detected_key_path']] ?? 0) + 1;
                        $record = $this->legacyCandidateRecord($category, $candidate);
                        if ($record['possible_external_category_id'] && $record['confidence'] === 'high') {
                            $strategyKey = ($record['local_category_id'] ?? '').'|'.$record['possible_external_category_id'];
                            $strategy[$strategyKey] = ['local_category_id' => $record['local_category_id'], 'ovoko_external_category_id' => $record['possible_external_category_id'], 'source' => $record['detected_key_path'], 'confidence' => $record['confidence']];
                        }
                        if (count($records) < $sampleLimit) $records[] = $record;
                    }
                }
            });
        }

        arsort($shapes); arsort($possibleKeys);

        return response()->json([
            'ok' => true, 'dry_run' => true, 'ovoko_write' => false, 'local_update' => false,
            'categories_checked' => $categoriesChecked,
            'legacy_payload_non_empty_count' => $legacyNonEmptyCount,
            'detected_legacy_payload_shapes' => $this->assocCounts($shapes),
            'possible_ovoko_keys' => $this->assocCounts($possibleKeys),
            'possible_ovoko_category_records_count' => array_sum($possibleKeys),
            'sample_possible_ovoko_category_records' => $records,
            'sample_current_missing_ovoko_mapping_with_legacy_payload' => $this->missingOvokoMappingLegacySamples($focusIds, $sampleLimit),
            'suggested_mapping_strategy' => array_values($strategy),
            'warnings' => $warnings,
        ]);
    }



    private function linkedOvokoPartsQuery(bool $onlyMissingMapping): Builder
    {
        $query = Part::query()->with(['category', 'marketplaceListings'])->whereNotNull('category_id')
            ->whereHas('marketplaceListings', fn ($q) => $q->where('marketplace', 'ovoko')->where(fn ($qq) => $qq->whereNotNull('external_offer_id')->orWhereNotNull('external_listing_id')))
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['archived', 'sold']))
            ->orderBy('id');
        if ($onlyMissingMapping && Schema::hasTable('marketplace_category_mappings')) {
            $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('marketplace_category_mappings')->whereColumn('marketplace_category_mappings.local_category_id', 'parts.category_id')->where('channel', 'ovoko'));
        }
        return $query;
    }

    private function fetchOvokoPartsByLinkedIds($parts, int $page, int $limit, array &$result): array
    {
        $ids = $parts->map(fn ($part) => $this->listingOvokoExternalId($this->ovokoListing($part)))->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
        if ($ids === []) return [];
        try {
            $api = app(MarketplaceApiManager::class)->client('ovoko')->fetchPartsPage($page, $limit);
            if (! ($api['api_ok'] ?? false)) $result['sample_errors'][] = ['type' => 'ovoko_api_status_not_ok', 'http_status' => $api['http_status'] ?? null, 'api_status_code' => $api['api_status_code'] ?? null, 'error' => $api['error'] ?? null];
            $byId = [];
            foreach (($api['parts'] ?? []) as $row) {
                $id = (string) ($row['external_offer_id'] ?? '');
                if ($id !== '' && in_array($id, $ids, true)) $byId[$id] = $row;
            }
            return $byId;
        } catch (\Throwable $e) {
            $result['sample_errors'][] = ['type' => 'ovoko_api_exception', 'message' => $e->getMessage()];
            return [];
        }
    }

    private function extractOvokoCategory(?array $row, ?array $tree = null, ?array &$result = null): ?array
    {
        if (! $row) return null;
        $raw = $row['raw_category_fields'] ?? [];
        $rawCategory = is_array($raw['category'] ?? null) ? $raw['category'] : [];
        $id = $row['ovoko_category_id'] ?? $raw['category_id'] ?? $raw['categoryId'] ?? $raw['part_category_id'] ?? $rawCategory['id'] ?? $rawCategory['category_id'] ?? null;
        $name = $row['ovoko_category_name'] ?? $raw['category_name'] ?? $raw['categoryName'] ?? $rawCategory['name'] ?? $rawCategory['pl'] ?? $rawCategory['en'] ?? null;
        $path = $row['ovoko_category_path'] ?? $raw['category_title_path'] ?? $raw['category_path'] ?? $raw['categoryPath'] ?? $rawCategory['path'] ?? $rawCategory['category_path'] ?? $rawCategory['category_title_path'] ?? null;
        if (blank($id) && blank($name) && blank($path)) return null;
        $warning = null;
        if (filled($id) && $tree && isset($tree['by_id'][(string) $id])) {
            $treeCategory = $tree['by_id'][(string) $id];
            $name = $treeCategory['pl'] ?? $name;
            $path = $this->ovokoCategoryPath($treeCategory, $tree['by_id'], 'pl');
            if ((int) ($treeCategory['level'] ?? 0) !== 3) $warning = 'ovoko_category_id_not_level_3';
        } elseif (filled($id) && $tree && count($tree['by_id']) > 0) {
            $warning = 'ovoko_category_id_missing_from_category_tree';
            if ($result !== null) $this->pushSample($result['sample_errors'], ['type' => $warning, 'ovoko_category_id' => (string) $id], 50);
        }
        return ['ovoko_category_id' => filled($id) ? (string) $id : null, 'ovoko_category_name' => filled($name) ? (string) $name : null, 'ovoko_category_path' => filled($path) ? (string) $path : null, 'category_tree_warning' => $warning, 'raw_category_fields' => $raw];
    }

    private function fetchOvokoCategoryTree(array &$result): array
    {
        try { $api = app(MarketplaceApiManager::class)->client('ovoko')->fetchCategories(); }
        catch (\Throwable $e) { $result['sample_errors'][] = ['type' => 'ovoko_categories_api_exception', 'message' => $e->getMessage()]; return ['categories' => [], 'by_id' => []]; }
        if (! ($api['api_ok'] ?? false)) $result['sample_errors'][] = ['type' => 'ovoko_categories_api_status_not_ok', 'http_status' => $api['http_status'] ?? null, 'api_status_code' => $api['api_status_code'] ?? null, 'error' => $api['error'] ?? null];
        $categories = $api['categories'] ?? [];
        $byId = [];
        foreach ($categories as $category) if (isset($category['id'])) $byId[(string) $category['id']] = $category;
        return ['categories' => $categories, 'by_id' => $byId];
    }

    private function ovokoCategoryPath(array $category, array $byId, string $locale): ?string
    {
        $parts = []; $current = $category; $guard = 0;
        while ($current && $guard++ < 10) {
            array_unshift($parts, (string) ($current[$locale] ?? $current['en'] ?? $current['id'] ?? ''));
            $parentId = $current['parent_id'] ?? null;
            $current = filled($parentId) && isset($byId[(string) $parentId]) ? $byId[(string) $parentId] : null;
        }
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));
        return $parts === [] ? null : implode(' > ', $parts);
    }

    private function localCategoryGroup(Part $part): array
    {
        return ['local_category_id' => $part->category_id, 'local_category_name' => $part->category?->name, 'local_category_path' => $part->category?->category_path ?? $part->category?->name, 'observed_ovoko_categories' => [], 'sample_parts' => []];
    }

    private function isBezKategorii(Part $part): bool
    {
        $value = mb_strtolower(trim((string) ($part->category?->category_path ?? $part->category?->name ?? '')));
        return $value === 'bez kategorii';
    }

    private function linkedProductSample(Part $part, ?string $ovokoId, ?array $category): array
    {
        return ['part_id' => $part->id, 'part_number' => $part->part_number, 'name' => $part->name, 'local_category_id' => $part->category_id, 'local_category_path' => $part->category?->category_path ?? $part->category?->name, 'ovoko_part_id' => $ovokoId, 'ovoko_category' => $category];
    }

    private function listingOvokoExternalId(?MarketplaceListing $listing): ?string
    {
        $id = $listing?->external_offer_id ?: $listing?->external_listing_id;
        return filled($id) ? (string) $id : null;
    }

    private function emptyLinkedCategoryMappingResponse(int $page, int $limit): array
    {
        return ['ok' => true, 'dry_run' => true, 'ovoko_write' => false, 'local_update' => false, 'ovoko_read_request' => true, 'page' => $page, 'limit' => $limit, 'linked_products_checked' => 0, 'linked_products_with_ovoko_category' => 0, 'local_categories_observed_count' => 0, 'suggested_mapping_count' => 0, 'ambiguous_mapping_count' => 0, 'unmapped_or_missing_category_count' => 0, 'skipped_uncategorized_count' => 0, 'suggested_mappings' => [], 'ambiguous_mappings' => [], 'sample_products_without_ovoko_category' => [], 'sample_errors' => [], 'warnings' => ['read_only_dry_run_no_ovoko_allegro_ebay_or_local_writes']];
    }

    private function analysePart(Part $part, bool $includeAlreadyListed): array
    {
        $part->loadMissing(['images', 'category', 'storageLocation', 'car', 'marketplaceListings']);
        $blockers = [];
        $warnings = [];
        $listing = $this->ovokoListing($part);
        $ovokoListingsCount = $this->ovokoListingsCount($part);
        $mapping = $this->ovokoCategoryMapping($part);
        $description = trim(strip_tags((string) (($part->description ?: $part->short_description) ?? '')));
        $price = $this->ovokoPrice($part);
        $imageCheck = $this->imageUrls($part);

        if ($listing && ! $includeAlreadyListed) $blockers[] = 'already_has_ovoko_listing';
        if ($ovokoListingsCount > 1) $blockers[] = 'conflicting_ovoko_listing_mapping';
        if ($listing && blank($listing->external_offer_id ?? $listing->external_listing_id)) $blockers[] = 'missing_ovoko_external_id';
        if (($part->status ?? null) === 'sold') $blockers[] = 'sold';
        if (($part->status ?? null) === 'archived') $blockers[] = 'archived';
        if ((bool) ($part->needs_review ?? false)) $blockers[] = 'needs_review';
        if ((bool) ($part->needs_listing ?? false)) $blockers[] = 'needs_listing';
        if (! is_numeric($part->quantity) || (int) $part->quantity <= 0) $blockers[] = 'quantity_not_positive';
        if (blank($part->part_number)) $blockers[] = 'missing_part_number';
        if (blank($part->name)) $blockers[] = 'missing_name';
        if ($description === '') $blockers[] = 'missing_description';
        if (! is_numeric($price) || (float) $price <= 0) $blockers[] = 'missing_price';
        if (blank($part->storageLocation?->name)) $blockers[] = 'missing_storage_location';
        if ($imageCheck['count'] < 1) $blockers[] = 'missing_images';
        if ($imageCheck['public_count'] < 1) $blockers[] = 'missing_public_image_url';
        if ($imageCheck['inaccessible_count'] > 0) $blockers[] = 'image_url_not_publicly_accessible';
        if (! $mapping) $blockers[] = 'missing_ovoko_category_mapping';
        elseif ($mapping->is_blocked) $blockers[] = 'blocked_ovoko_category_mapping';
        elseif (blank($mapping->external_category_id)) $blockers[] = 'missing_ovoko_category_id';
        if (blank($part->car_id) && ! is_array($part->vehicle_snapshot)) $warnings[] = 'missing_vehicle_data_check_if_ovoko_requires_it';

        $storageLocation = $this->storageLocationDiagnostics($part);
        $categoryMapping = $this->categoryMappingDiagnostics($part, $mapping);
        $payload = $this->payloadPreview($part, $description, $price, $mapping, $imageCheck['urls']);
        $sample = ['part_id' => $part->id, 'part_number' => $part->part_number, 'name' => $part->name, 'has_ovoko_listing' => (bool) $listing, 'storage_location' => $storageLocation, 'ovoko_category_mapping' => $categoryMapping, 'blockers' => array_values(array_unique($blockers)), 'warnings' => array_values(array_unique($warnings))];

        return ['already_has_ovoko_listing' => (bool) $listing, 'listing' => $listing, 'blockers' => $sample['blockers'], 'warnings' => $sample['warnings'], 'sample' => $sample + ['ovoko_external_id' => $listing?->external_offer_id ?? $listing?->external_listing_id], 'payload' => $payload];
    }

    private function payloadPreview(Part $part, string $description, ?float $price, ?MarketplaceCategoryMapping $mapping, array $imageUrls): array
    {
        return ['dry_run' => true, 'will_make_ovoko_request' => false, 'part_id' => $part->id, 'sku' => $part->sku, 'part_number' => $part->part_number, 'name' => $part->name, 'description' => $description, 'price' => $price, 'currency' => $part->currency ?: 'PLN', 'quantity' => (int) $part->quantity, 'storage_location' => $part->storageLocation?->name, 'ovoko_category_id' => $mapping?->external_category_id, 'local_category_id' => $part->category_id, 'local_category_path' => $part->category?->category_path ?? $part->category?->name, 'image_urls' => $imageUrls, 'vehicle' => ['car_id' => $part->car_id, 'snapshot' => $part->vehicle_snapshot], 'condition' => $part->condition_notes ?: 'used'];
    }

    private function partsQuery(Request $request): Builder
    {
        $query = Part::query()->with(['images', 'category', 'storageLocation', 'car', 'marketplaceListings'])->orderBy('id');
        if (! $request->boolean('include_archived', false)) $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'archived'));
        $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'sold'))->where('quantity', '>', 0);
        if (! $request->boolean('include_needs_review', false)) $query->where(fn ($q) => $q->where('needs_review', false)->orWhereNull('needs_review'));
        if (! $request->boolean('include_needs_listing', false)) $query->where(fn ($q) => $q->where('needs_listing', false)->orWhereNull('needs_listing'));
        if ($request->boolean('only_ready', true)) $query->where(fn ($q) => $q->where('is_visible_storefront', true)->orWhereIn('status', ['ready', 'published']));
        return $query;
    }

    private function imageUrls(Part $part): array
    {
        $urls = [];
        $inaccessible = 0;
        foreach ($part->images as $image) {
            $url = method_exists($image, 'listingUrl') ? $image->listingUrl() : null;
            if (blank($url)) continue;
            $urls[] = $url;
            if (! $this->imageUrlLooksAccessible((string) $url, $image->path ?? null)) $inaccessible++;
        }
        return ['count' => $part->images->count(), 'public_count' => count($urls), 'inaccessible_count' => $inaccessible, 'urls' => array_values($urls)];
    }

    private function imageUrlLooksAccessible(string $url, ?string $path): bool
    {
        if ($path && Storage::disk('public')->exists(ltrim(str_replace('storage/', '', $path), '/'))) return true;
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) return false;
        try { return Http::timeout(3)->head($url)->successful(); } catch (\Throwable) { return false; }
    }



    private function decodeLegacyPayload(mixed $payload): mixed
    {
        if (is_array($payload)) return $payload;
        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        return null;
    }

    private function findOvokoLegacyPayloadCandidates(array $payload, string $path = 'legacy_payload'): array
    {
        $needles = '/(ovoko|rrr|rrr\.lt|category_id|categoryId|category|marketplace_mappings|marketplaces|external_id|externalCategoryId|external_category_id|category_path|category_name)/i';
        $out = [];
        foreach ($payload as $key => $value) {
            $current = $path.'.'.$key;
            $keyHit = preg_match($needles, (string) $key) === 1;
            $valueText = is_scalar($value) ? (string) $value : json_encode($value);
            $valueHit = is_string($valueText) && preg_match($needles, $valueText) === 1;
            if ($keyHit || $valueHit) $out[] = ['detected_key_path' => $current, 'detected_value' => $value, 'key_hit' => $keyHit, 'value_hit' => $valueHit];
            if (is_array($value)) $out = array_merge($out, $this->findOvokoLegacyPayloadCandidates($value, $current));
        }
        return $out;
    }

    private function legacyCandidateRecord(array $category, array $candidate): array
    {
        $value = $candidate['detected_value'];
        $path = $candidate['detected_key_path'];
        $flat = is_array($value) ? $value : [];
        $externalId = $flat['external_category_id'] ?? $flat['externalCategoryId'] ?? $flat['category_id'] ?? $flat['categoryId'] ?? $flat['id'] ?? null;
        if (! $externalId && preg_match('/(external_category_id|externalCategoryId|category_id|categoryId|external_id)$/i', $path) && is_scalar($value)) $externalId = (string) $value;
        $name = $flat['external_category_name'] ?? $flat['category_name'] ?? $flat['categoryName'] ?? $flat['name'] ?? null;
        $categoryPath = $flat['external_category_path'] ?? $flat['category_path'] ?? $flat['categoryPath'] ?? $flat['path'] ?? null;
        $hasOvokoContext = preg_match('/(ovoko|rrr)/i', $path.' '.(is_scalar($value) ? (string) $value : json_encode($value))) === 1;
        $confidence = $hasOvokoContext && $externalId ? 'high' : ($hasOvokoContext || $externalId ? 'medium' : 'low');
        return [
            'local_category_id' => $category['id'] ?? null,
            'local_category_path' => $category['category_path'] ?? $category['name'] ?? null,
            'part_categories_external_id' => $category['external_id'] ?? null,
            'detected_key_path' => $path,
            'detected_value' => is_array($value) ? $value : (string) $value,
            'possible_external_category_id' => $externalId ? (string) $externalId : null,
            'possible_external_category_name' => $name ? (string) $name : null,
            'possible_external_category_path' => $categoryPath ? (string) $categoryPath : null,
            'confidence' => $confidence,
        ];
    }

    private function missingOvokoMappingLegacySamples(array $focusIds, int $limit): array
    {
        if (! Schema::hasTable('part_categories') || ! Schema::hasColumn('part_categories', 'legacy_payload')) return [];
        $query = DB::table('part_categories')->select($this->safeSelectColumns('part_categories', ['id', 'name', 'category_path', 'external_id', 'legacy_payload']))->whereNotNull('legacy_payload')->where('legacy_payload', '!=', '');
        if ($focusIds !== []) $query->whereIn('id', $focusIds);
        return $query->limit($limit)->get()->map(function ($row) {
            $cat = (array) $row; $payload = $this->decodeLegacyPayload($cat['legacy_payload'] ?? null);
            $fragment = is_array($payload) ? ($payload['marketplace_mappings'] ?? $payload['marketplaces'] ?? null) : null;
            $text = json_encode($payload);
            return $cat + [
                'legacy_payload' => $payload,
                'marketplace_mappings_fragment' => $fragment,
                'has_ovoko_or_rrr' => is_string($text) && preg_match('/(ovoko|rrr|rrr\.lt)/i', $text) === 1,
                'local_external_id_matches_payload_value' => isset($cat['external_id']) && $cat['external_id'] !== null && is_string($text) && str_contains($text, (string) $cat['external_id']),
                'current_ovoko_mapping_exists' => Schema::hasTable('marketplace_category_mappings') ? DB::table('marketplace_category_mappings')->where('local_category_id', $cat['id'])->where('channel', 'ovoko')->exists() : false,
            ];
        })->filter(fn ($row) => ! $row['current_ovoko_mapping_exists'])->values()->all();
    }

    private function assocCounts(array $counts): array
    {
        return collect($counts)->map(fn ($count, $value) => ['value' => $value, 'count' => $count])->values()->all();
    }

    private function databaseTables(): array { try { return array_map(fn ($row) => reset($row), array_map('get_object_vars', DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))); } catch (\Throwable) { try { return array_map(fn ($row) => reset($row), array_map('get_object_vars', DB::select('SHOW TABLES'))); } catch (\Throwable) { return []; } } }
    private function looksLikeOvokoCategoryColumn(string $column): bool { return (bool) preg_match('/(ovoko|category|external|marketplace|source|import|slug|path|name|metadata|json|settings|raw|legacy)/i', $column); }
    private function safeCount(string $table): int { try { return (int) DB::table($table)->count(); } catch (\Throwable) { return 0; } }
    private function nonEmptyCount(string $table, string $column): int { try { return (int) DB::table($table)->whereNotNull($column)->where($column, '!=', '')->count(); } catch (\Throwable) { return 0; } }
    private function safeSelectColumns(string $table, array $wanted): array { $columns = Schema::getColumnListing($table); return array_values(array_intersect($wanted, $columns)) ?: ['id']; }
    private function columnSamples(string $table, string $column, int $limit): array { try { return DB::table($table)->select($this->safeSelectColumns($table, ['id', 'local_category_id', 'channel', 'external_category_id', 'external_category_name', 'external_category_path', 'name', 'category_path', 'slug', $column]))->whereNotNull($column)->where($column, '!=', '')->limit($limit)->get()->map(fn ($r) => (array) $r)->all(); } catch (\Throwable) { return []; } }
    private function tableSummary(?string $table, array $columns, int $limit): array { if (! $table || ! Schema::hasTable($table)) return ['table' => $table, 'exists' => false]; return ['table' => $table, 'exists' => true, 'record_count' => $this->safeCount($table), 'columns' => Schema::getColumnListing($table), 'samples' => DB::table($table)->select($this->safeSelectColumns($table, $columns))->limit($limit)->get()->map(fn ($r) => (array) $r)->all()]; }
    private function marketplaceMappingsSummary(int $limit): array { if (! Schema::hasTable('marketplace_category_mappings')) return ['exists' => false]; $countPerChannel = DB::table('marketplace_category_mappings')->select('channel', DB::raw('count(*) as count'))->groupBy('channel')->orderBy('channel')->get()->map(fn ($r) => (array) $r)->all(); $ovokoCount = (int) DB::table('marketplace_category_mappings')->where('channel', 'ovoko')->count(); if (! collect($countPerChannel)->contains(fn ($row) => ($row['channel'] ?? null) === 'ovoko')) $countPerChannel[] = ['channel' => 'ovoko', 'count' => 0]; return ['exists' => true, 'count_per_channel' => $countPerChannel, 'ovoko_channel_exists' => $ovokoCount > 0, 'ovoko_count' => $ovokoCount, 'non_ovoko_external_category_id_count' => DB::table('marketplace_category_mappings')->where('channel', '!=', 'ovoko')->whereNotNull('external_category_id')->count(), 'samples' => DB::table('marketplace_category_mappings')->limit($limit)->get()->map(fn ($r) => (array) $r)->all()]; }
    private function partsCategorySummary(int $limit, array $matches = [], ?string $categoryTable = null): array { if (! Schema::hasTable('parts')) return ['exists' => false]; $matchByCategory = collect($matches)->keyBy('local_category_id'); $samples = DB::table('parts')->select($this->safeSelectColumns('parts', ['id', 'part_number', 'name', 'category_id']))->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('marketplace_listings')->whereColumn('marketplace_listings.part_id', 'parts.id')->where('marketplace', 'ovoko'))->limit($limit)->get()->map(function ($r) use ($matchByCategory, $categoryTable) { $part = (array) $r; $match = $matchByCategory->get($part['category_id'] ?? null, []); $category = ($categoryTable && isset($part['category_id'])) ? DB::table($categoryTable)->select($this->safeSelectColumns($categoryTable, ['id', 'name', 'category_path']))->where('id', $part['category_id'])->first() : null; return $part + ['local_category_id' => $part['category_id'] ?? null, 'local_category_path' => $match['local_category_path'] ?? ((array) $category)['category_path'] ?? ((array) $category)['name'] ?? null, 'found_possible_ovoko_category_source' => $match['found_possible_ovoko_category_source'] ?? null, 'possible_external_category_id' => $match['possible_external_category_id'] ?? null, 'confidence' => $match['confidence'] ?? 'none', 'source_table' => $match['source_table'] ?? null, 'source_column' => $match['source_column'] ?? null]; })->all(); return ['exists' => true, 'record_count' => $this->safeCount('parts'), 'with_category_id_count' => Schema::hasColumn('parts', 'category_id') ? DB::table('parts')->whereNotNull('category_id')->count() : null, 'samples_without_ovoko_listing' => $samples]; }
    private function ovokoCategoryLikeRows(array $tables, int $limit): array { $rows = []; foreach ($tables as $table) { if (! Schema::hasTable($table)) continue; $cols = Schema::getColumnListing($table); if (! preg_match('/(ovoko|category)/i', $table) && ! in_array('external_category_id', $cols, true)) continue; foreach (array_intersect(['id','name','category_path','external_id','external_category_id','external_category_name','external_category_path','channel','source_system'], $cols) as $_) {} $select = $this->safeSelectColumns($table, ['id','name','category_path','external_id','external_category_id','external_category_name','external_category_path','channel','source_system']); foreach (DB::table($table)->select($select)->limit($limit)->get() as $r) { $a=(array)$r; $rows[]=['source_table'=>$table,'source_column'=>isset($a['external_category_id'])?'external_category_id':(isset($a['external_id'])?'external_id':'id'),'id'=>$a['external_category_id']??$a['external_id']??$a['id']??null,'name'=>$a['external_category_name']??$a['name']??null,'path'=>$a['external_category_path']??$a['category_path']??null,'raw'=>$a]; } } return $rows; }
    private function norm(?string $value): string { return mb_strtolower(trim((string) $value)); }
    private function categoryNameMatches($categories, array $ovokoRows, int $limit): array { $out=[]; foreach ($categories as $cat) { $c=(array)$cat; foreach ($ovokoRows as $row) { $conf = null; if ($this->norm($c['category_path'] ?? null) !== '' && $this->norm($c['category_path'] ?? null) === $this->norm($row['path'] ?? null)) $conf='exact_path_match'; elseif ($this->norm($c['name'] ?? null) !== '' && $this->norm($c['name'] ?? null) === $this->norm($row['name'] ?? null)) $conf='exact_name_match'; elseif ($this->norm($c['name'] ?? null) !== '' && str_contains($this->norm($row['path'] ?? ''), $this->norm($c['name'] ?? null))) $conf='partial_match'; if ($conf) { $out[]=['local_category_id'=>$c['id']??null,'local_category_name'=>$c['name']??null,'local_category_path'=>$c['category_path']??null,'possible_external_category_id'=>$row['id'],'found_possible_ovoko_category_source'=>$row['name'] ?: $row['path'],'confidence'=>$conf,'source_table'=>$row['source_table'],'source_column'=>$row['source_column']]; if (count($out) >= $limit) return $out; } } } return $out; }
    private function unmatchedLocalCategorySamples($categories, array $matches, int $limit): array { $matched = array_flip(array_filter(array_column($matches, 'local_category_id'))); return $categories->filter(fn ($cat) => ! isset($matched[((array) $cat)['id'] ?? null]))->take($limit)->map(fn ($cat) => (array) $cat)->values()->all(); }
    private function storageLocationDiagnostics(Part $part): array { return ['source' => 'parts.storage_location_id -> storage_locations.name', 'storage_location_id' => $part->storage_location_id, 'resolved_name' => $part->storageLocation?->name, 'has_relation' => $part->storageLocation !== null]; }
    private function categoryMappingDiagnostics(Part $part, ?MarketplaceCategoryMapping $mapping): array { return ['source' => 'marketplace_category_mappings.local_category_id = parts.category_id and channel = ovoko', 'local_category_id' => $part->category_id, 'local_category_path' => $part->category?->category_path ?? $part->category?->name, 'mapping_id' => $mapping?->id, 'channel' => $mapping?->channel, 'external_category_id' => $mapping?->external_category_id, 'is_blocked' => $mapping?->is_blocked]; }
    private function ovokoPrice(Part $part): ?float { $value = $part->ovoko_price ?? null; return is_numeric($value) ? (float) $value : (is_numeric($part->price ?? null) ? (float) $part->price : null); }
    private function ovokoListing(Part $part): ?MarketplaceListing { return $part->marketplaceListings->first(fn ($listing) => $listing->marketplace === 'ovoko'); }
    private function ovokoListingsCount(Part $part): int { return $part->marketplaceListings->filter(fn ($listing) => $listing->marketplace === 'ovoko')->count(); }
    private function ovokoCategoryMapping(Part $part): ?MarketplaceCategoryMapping { if (! Schema::hasTable('marketplace_category_mappings') || ! $part->category_id) return null; return MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->where('channel', 'ovoko')->first(); }
    private function pushSample(array &$items, array $item, int $limit): void { if (count($items) < $limit) $items[] = $item; }
    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidTokenResponse(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
    private function emptyDryRunSummary(string $mode, int $page, int $limit): array { return ['ok' => true, 'dry_run' => true, 'local_update_only' => false, 'ovoko_write' => false, 'mode' => $mode, 'page' => $page, 'limit' => $limit, 'local_candidate_parts_count' => 0, 'already_has_ovoko_listing_count' => 0, 'missing_ovoko_listing_candidate_count' => 0, 'would_create_ovoko_count' => 0, 'blocked_count' => 0, 'warning_count' => 0, 'sample_would_create' => [], 'sample_already_listed' => [], 'sample_blocked' => [], 'sample_already_listed_blocked' => [], 'sample_missing_listing_blocked' => [], 'sample_create_missing_blocked' => [], 'sample_payloads' => [], 'required_fields' => self::REQUIRED_FIELDS, 'blockers' => [], 'top_blockers_already_listed' => [], 'top_blockers_missing_listing' => [], 'warnings' => ['dry_run_only_no_ovoko_or_other_marketplace_writes' => 1]]; }
}

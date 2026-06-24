<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Route;
use Throwable;

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
        $existingMappingsCount = Schema::hasTable('marketplace_category_mappings') ? (int) DB::table('marketplace_category_mappings')->where('channel', 'ovoko')->distinct('local_category_id')->count('local_category_id') : 0;
        $result['categories_with_existing_ovoko_mapping_count'] = $existingMappingsCount;
        $result['categories_missing_ovoko_mapping_count'] = max(0, $this->localCategoriesCount() - $existingMappingsCount);
        $parts = $this->linkedOvokoPartsQuery($onlyMissing)->forPage($page, $limit)->get();

        $categoryTree = $this->fetchOvokoCategoryTree($result);
        $ovokoPartsById = $this->fetchOvokoPartsSnapshotByLinkedIds($parts, $result);
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
                $result['no_evidence_count']++;
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
                $result['high_confidence_mapping_count']++;
                $mapping = [
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
                $result['suggested_mappings'][] = $mapping;
                $this->pushSample($result['sample_high_confidence_mappings'], ['local_category_id' => $mapping['local_category_id'], 'local_category_path' => $mapping['local_category_path'], 'ovoko_category_id' => $mapping['ovoko_category_id'], 'ovoko_category_path' => $mapping['ovoko_category_path'], 'evidence_count' => $mapping['evidence_count'], 'match_type' => $mapping['match_type']], $sampleLimit);
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
        $ovokoPartsById = $this->fetchOvokoPartsSnapshotByLinkedIds($parts, $result);
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


    public function dryRunOvokoCategoryPathMapping(Request $request)
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(300, (int) $request->query('sample_limit', 300)));
        $only = (string) $request->query('only', 'all');
        $format = (string) $request->query('format', 'json');
        $allowedOnly = ['all', 'matched', 'unmatched', 'exact_path', 'normalized_path', 'exact_name'];
        if (! in_array($only, $allowedOnly, true)) return response()->json(['ok' => false, 'dry_run' => true, 'error_message' => 'Unsupported only value.', 'allowed_only' => $allowedOnly], 422);
        if (! in_array($format, ['json', 'csv'], true)) return response()->json(['ok' => false, 'dry_run' => true, 'error_message' => 'Unsupported format value.', 'allowed_format' => ['json', 'csv']], 422);
        $includeUnmatched = $request->boolean('include_unmatched', false) || $only === 'unmatched';

        $result = ['ok'=>true,'dry_run'=>true,'ovoko_write'=>false,'local_update'=>false,'only'=>$only,'format'=>$format,'sample_limit'=>$sampleLimit,'include_unmatched'=>$includeUnmatched,'local_categories_count'=>0,'ovoko_categories_count'=>0,'ovoko_level_3_count'=>0,'exact_path_match_count'=>0,'normalized_path_match_count'=>0,'exact_name_match_count'=>0,'ambiguous_name_match_count'=>0,'unmatched_count'=>0,'skipped_uncategorized_count'=>0,'preview_mappings'=>[],'unmatched_categories'=>[],'sample_exact_path_matches'=>[],'sample_normalized_path_matches'=>[],'sample_exact_name_matches'=>[],'sample_ambiguous'=>[],'sample_unmatched'=>[],'sample_errors'=>[],'warnings'=>['read_only_dry_run_no_ovoko_allegro_ebay_or_local_writes']];

        if (! Schema::hasTable('part_categories')) {
            $result['warnings'][] = 'part_categories_table_missing';
            return $this->pathMappingResponse($result, $format, $only);
        }

        $tree = $this->fetchOvokoCategoryTree($result);
        $ovoko = $this->ovokoCategoryIndexes($tree);
        $result['ovoko_categories_count'] = count($tree['categories']);
        $result['ovoko_level_3_count'] = count($ovoko['level3']);
        $linkedConsensus = $this->linkedConsensusMappingsFromAutorun($request, $sampleLimit);
        if ($linkedConsensus === []) $result['warnings'][] = 'linked_products_consensus_not_loaded_pass_run_id_to_combine_results';

        $partCounts = Schema::hasTable('parts') ? DB::table('parts')->select('category_id', DB::raw('count(*) as c'))->whereNotNull('category_id')->groupBy('category_id')->pluck('c','category_id')->all() : [];
        $select = $this->safeSelectColumns('part_categories', ['id','name','category_path']);
        DB::table('part_categories')->select($select)->orderBy('id')->chunk(500, function ($rows) use (&$result, $ovoko, $linkedConsensus, $partCounts, $sampleLimit, $only, $includeUnmatched): void {
            foreach ($rows as $row) {
                $local = (array) $row;
                $result['local_categories_count']++;
                $path = (string) ($local['category_path'] ?? $local['name'] ?? '');
                $name = (string) ($local['name'] ?? '');
                if ($this->isUncategorizedCategoryValue($path) || $this->isUncategorizedCategoryValue($name)) { $result['skipped_uncategorized_count']++; continue; }

                $partsCount = (int)($partCounts[$local['id']] ?? 0);
                $match = $this->matchLocalCategoryToOvokoPath($local, $ovoko);
                if ($match['match_type'] === 'unmatched') {
                    $result['unmatched_count']++;
                    $unmatched = ['local_category_id'=>(int)$local['id'],'local_category_name'=>$name,'local_category_path'=>$path,'local_parts_count'=>$partsCount,'reason'=>'no_exact_or_normalized_ovoko_path_match'];
                    $this->pushSample($result['sample_unmatched'], $unmatched, $sampleLimit);
                    if ($includeUnmatched) $result['unmatched_categories'][] = $unmatched;
                    continue;
                }
                if ($match['match_type'] === 'ambiguous_name_match') {
                    $result['ambiguous_name_match_count']++;
                    $this->pushSample($result['sample_ambiguous'], $match['sample'], $sampleLimit);
                    continue;
                }

                $bucket = $match['match_type'].'_count';
                if (array_key_exists($bucket, $result)) $result[$bucket]++;
                $mapping = $this->pathMappingPreview($local, $match, $partsCount, $linkedConsensus[(string)$local['id']] ?? null);
                if ($this->includePathMappingForOnly($mapping['match_type'], $only)) $result['preview_mappings'][] = $mapping;
                if ($match['match_type'] === 'exact_path_match') $this->pushSample($result['sample_exact_path_matches'], $mapping, $sampleLimit);
                if ($match['match_type'] === 'normalized_path_match') $this->pushSample($result['sample_normalized_path_matches'], $mapping, $sampleLimit);
                if ($match['match_type'] === 'exact_name_match') $this->pushSample($result['sample_exact_name_matches'], $mapping, $sampleLimit);
            }
        });

        $result['preview_mappings'] = array_slice($result['preview_mappings'], 0, $sampleLimit);
        if (! $includeUnmatched) unset($result['unmatched_categories']);
        return $this->pathMappingResponse($result, $format, $only);
    }

    public function dryRunSyncOvokoTreeToShopCategories(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(200, (int) $request->query('sample_limit', 50)));
        $result = [
            'ok' => true,
            'dry_run' => true,
            'local_update' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'ovoko_categories_count' => 0,
            'local_categories_count' => 0,
            'existing_mapping_match_count' => 0,
            'external_id_match_count' => 0,
            'exact_path_match_count' => 0,
            'normalized_path_match_count' => 0,
            'already_existing_count' => 0,
            'would_create_count' => 0,
            'would_create_level_1_count' => 0,
            'would_create_level_2_count' => 0,
            'would_create_level_3_count' => 0,
            'would_create_mapping_count' => 0,
            'would_skip_duplicate_count' => 0,
            'would_skip_ambiguous_count' => 0,
            'sample_existing_matches' => [],
            'sample_would_create' => [],
            'sample_parent_chains' => [],
            'sample_ambiguous' => [],
            'sample_old_unmatched_local_categories' => [],
            'warnings' => ['read_only_dry_run_no_ovoko_allegro_ebay_or_local_writes_no_part_categories_or_mapping_writes'],
        ];

        if (! Schema::hasTable('marketplace_categories') || ! Schema::hasTable('part_categories')) {
            $result['warnings'][] = 'required_table_missing';
            return response()->json($result);
        }

        $ovoko = MarketplaceCategory::query()
            ->where('channel', 'ovoko')
            ->get(['external_category_id', 'parent_external_category_id', 'level', 'name', 'full_path'])
            ->map(fn (MarketplaceCategory $category): array => [
                'id' => (string) $category->external_category_id,
                'parent_id' => filled($category->parent_external_category_id) ? (string) $category->parent_external_category_id : null,
                'level' => (int) ($category->level ?: $this->pathLevel((string) ($category->full_path ?: $category->name))),
                'name' => (string) $category->name,
                'full_path' => (string) ($category->full_path ?: $category->name),
            ])
            ->filter(fn (array $row): bool => filled($row['id']) && filled($row['full_path']))
            ->sortBy([['level', 'asc'], ['full_path', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $result['ovoko_categories_count'] = $ovoko->count();
        $byOvokoId = $ovoko->keyBy('id');

        $localSelect = $this->safeSelectColumns('part_categories', ['id', 'parent_id', 'name', 'category_path', 'external_id']);
        $locals = DB::table('part_categories')->select($localSelect)->get()->map(fn ($row): array => (array) $row);
        $result['local_categories_count'] = $locals->count();

        $mappingByOvokoId = Schema::hasTable('marketplace_category_mappings')
            ? DB::table('marketplace_category_mappings')
                ->where('channel', 'ovoko')
                ->whereNotNull('external_category_id')
                ->select($this->safeSelectColumns('marketplace_category_mappings', ['local_category_id', 'external_category_id', 'external_category_name', 'external_category_path']))
                ->get()
                ->groupBy(fn ($row): string => (string) $row->external_category_id)
            : collect();

        $localsById = $locals->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''));
        $externalIdIndex = Schema::hasColumn('part_categories', 'external_id') ? $locals->filter(fn (array $row): bool => filled($row['external_id'] ?? null))->groupBy(fn (array $row): string => (string) $row['external_id']) : collect();
        $exactPathIndex = $locals->filter(fn (array $row): bool => filled($row['category_path'] ?? null))->groupBy(fn (array $row): string => (string) $row['category_path']);
        $normalizedPathIndex = $locals->filter(fn (array $row): bool => filled($row['category_path'] ?? null))->groupBy(fn (array $row): string => $this->normalizeTreeSyncPath((string) $row['category_path']));

        $matchedLocalIds = [];
        $matchedOvokoIds = [];
        $wouldCreateIds = [];

        foreach ($ovoko as $category) {
            $match = $this->matchOvokoTreeCategoryToLocal($category, $mappingByOvokoId, $externalIdIndex, $exactPathIndex, $normalizedPathIndex, $localsById);
            if (($match['ambiguous'] ?? false) === true) {
                $result['would_skip_ambiguous_count']++;
                $this->pushSample($result['sample_ambiguous'], $match['sample'], $sampleLimit);
                continue;
            }

            if ($match['local'] !== null) {
                $local = $match['local'];
                $type = $match['match_type'];
                $result[$type.'_count']++;
                $result['already_existing_count']++;
                if (isset($matchedLocalIds[(string) $local['id']])) $result['would_skip_duplicate_count']++;
                $matchedLocalIds[(string) $local['id']] = true;
                $matchedOvokoIds[$category['id']] = ['local_id' => (int) $local['id'], 'match_type' => $type];
                if ($type !== 'existing_mapping_match') $result['would_create_mapping_count']++;
                $this->pushSample($result['sample_existing_matches'], [
                    'local_category_id' => (int) $local['id'],
                    'local_category_name' => $local['name'] ?? null,
                    'local_category_path' => $local['category_path'] ?? ($local['name'] ?? null),
                    'ovoko_category_id' => $category['id'],
                    'ovoko_name' => $category['name'],
                    'ovoko_path' => $category['full_path'],
                    'match_type' => $type,
                ], $sampleLimit);
                continue;
            }

            $wouldCreateIds[$category['id']] = true;
            $level = max(1, (int) $category['level']);
            $result['would_create_count']++;
            if ($level >= 1 && $level <= 3) $result['would_create_level_'.$level.'_count']++;

            $parentId = $category['parent_id'];
            $parentMatch = $parentId ? ($matchedOvokoIds[$parentId] ?? null) : null;
            $parentWouldCreate = $parentId ? isset($wouldCreateIds[$parentId]) : false;
            $sample = [
                'ovoko_category_id' => $category['id'],
                'ovoko_parent_id' => $parentId,
                'ovoko_level' => $level,
                'name' => $category['name'],
                'full_path' => $category['full_path'],
                'parent_full_path' => $parentId && $byOvokoId->has($parentId) ? $byOvokoId->get($parentId)['full_path'] : null,
                'would_parent_exist' => $parentId === null || $parentMatch !== null,
                'would_parent_be_created' => $parentWouldCreate,
                'suggested_local_parent_id' => $parentMatch['local_id'] ?? null,
            ];
            $this->pushSample($result['sample_would_create'], $sample, $sampleLimit);
            if ($parentWouldCreate) $this->pushSample($result['sample_parent_chains'], $sample, $sampleLimit);
        }

        foreach ($locals as $local) {
            if (! isset($matchedLocalIds[(string) ($local['id'] ?? '')])) {
                $this->pushSample($result['sample_old_unmatched_local_categories'], [
                    'local_category_id' => (int) ($local['id'] ?? 0),
                    'local_category_name' => $local['name'] ?? null,
                    'local_category_path' => $local['category_path'] ?? ($local['name'] ?? null),
                    'note' => 'left_unchanged_for_separate_analysis',
                ], $sampleLimit);
            }
        }

        return response()->json($result);
    }

    public function ovokoCategoryMappingAutorun(Request $request)
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        return response(<<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Ovoko category mapping autorun</title><style>body{font-family:system-ui;margin:24px;max-width:1200px}button{font-size:16px;padding:8px 14px;margin-right:8px}pre{background:#111;color:#eee;padding:16px;overflow:auto;max-height:360px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.card{border:1px solid #ddd;padding:12px;border-radius:8px;overflow-wrap:anywhere}.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.meta{margin:16px 0;border:1px solid #ddd;border-radius:8px;padding:12px}.meta div{margin:4px 0}.error{color:#b00020;font-weight:700}</style></head><body>
<h1>Ovoko category mapping autorun</h1>
<div class="toolbar"><button id="start">Start</button><button id="resume">Resume active run</button><button id="manualTick" disabled>Manual tick</button><button id="refresh" disabled>Refresh status</button> <span id="state">idle</span></div>
<div class="meta"><div><b>run_id:</b> <code id="runId">-</code></div><div><b>last_request_url:</b> <code id="lastRequestUrl">-</code></div><div><b>last_response_status:</b> <code id="lastResponseStatus">-</code></div><div><b>last_error:</b> <span id="lastError" class="error">-</span></div><div><b>warning:</b> <span id="lastWarning" class="error">-</span></div><div><b>next_url:</b> <code id="nextUrl">-</code></div><div><a id="results" href="#" style="display:none">Results for current run</a></div></div>
<div class="grid" id="cards"></div><h2>Request / response preview</h2><h3>Start response JSON</h3><pre id="startOut">{}</pre><h3>Last tick/status response JSON</h3><pre id="tickOut">{}</pre><h3>Samples / errors</h3><pre id="out">{}</pre>
<script>
const token=new URLSearchParams(location.search).get('token')||'';let currentRunId=null;let nextUrl=null;let running=false;
function sleep(ms){return new Promise(r=>setTimeout(r,ms))}
function absoluteUrl(url){return new URL(url, window.location.origin).toString()}
function setText(id,value){document.getElementById(id).textContent=value||'-'}
function setControls(isRunning){running=isRunning;const hasRun=!!currentRunId;document.getElementById('start').disabled=isRunning;document.getElementById('start').textContent=isRunning?'Running':'Start';document.getElementById('manualTick').disabled=isRunning||!hasRun;document.getElementById('refresh').disabled=!hasRun;document.getElementById('resume').disabled=isRunning}
function resultsUrl(){return '/tools/results-ovoko-category-mapping-autorun?token='+encodeURIComponent(token)+'&run_id='+encodeURIComponent(currentRunId)}
function tickUrl(){return '/tools/run-ovoko-category-mapping-autorun?token='+encodeURIComponent(token)+'&run_id='+encodeURIComponent(currentRunId)}
function statusUrl(){return '/tools/status-ovoko-category-mapping-autorun?token='+encodeURIComponent(token)+'&run_id='+encodeURIComponent(currentRunId)}
function ensureNextUrl(d){if((d.status==='started'||d.status==='running')&&d.run_id&&!d.next_url&&(d.remaining_count??1)>0){setText('lastWarning','start_missing_next_url_fallback_used');return tickUrl()}setText('lastWarning','-');return d.next_url||null}
function render(d, target){if(!d)return;currentRunId=d.run_id||currentRunId;nextUrl=ensureNextUrl(d);setText('runId',currentRunId);setText('nextUrl',nextUrl);document.getElementById('state').textContent=(d.status||'')+' '+(d.progress_percent??0)+'%';const keys=['stage','time_spent_seconds','processed_count','processed_total','remaining_count','progress_percent','snapshot','mapping','current_batch','suggested_mapping_count','high_confidence_mapping_count','medium_confidence_mapping_count','ambiguous_mapping_count','no_evidence_count','categories_with_existing_ovoko_mapping_count','categories_missing_ovoko_mapping_count'];document.getElementById('cards').innerHTML=keys.map(k=>`<div class="card"><b>${k}</b><br>${typeof d[k]==='object'?JSON.stringify(d[k]):(d[k]??'')}</div>`).join('');document.getElementById('out').textContent=JSON.stringify({warnings:d.warnings,sample_errors:d.sample_errors,sample_suggested_mappings:d.sample_suggested_mappings,sample_high_confidence_mappings:d.sample_high_confidence_mappings,sample_ambiguous_mappings:d.sample_ambiguous_mappings},null,2);if(target)document.getElementById(target).textContent=JSON.stringify(d,null,2);if(currentRunId){let a=document.getElementById('results');a.href=resultsUrl();a.style.display='inline'}setControls(running)}
async function call(url, target, retry=0){const resolved=absoluteUrl(url);const started=Date.now();setText('lastRequestUrl',resolved);setText('lastError','-');try{let r=await fetch(resolved,{cache:'no-store'});const spent=(Date.now()-started)/1000;if(spent>60)setText('lastWarning','tick_taking_too_long');setText('lastResponseStatus',String(r.status));let text=await r.text();let d;try{d=text?JSON.parse(text):{}}catch(e){throw new Error('Invalid JSON from '+resolved+': '+text.slice(0,300))}if(!r.ok)throw new Error('HTTP '+r.status+': '+(d.error_message||text.slice(0,300)));return d}catch(e){setText('lastError',e.message);if(retry<2){await sleep(1000);return call(url,target,retry+1)}throw e}}
async function autoTick(firstUrl){setControls(true);let url=firstUrl;try{while(url){await sleep(650);const d=await call(url,'tickOut');render(d,'tickOut');if(d.status==='failed'){setText('lastError',d.error_message||'Run failed');break}if(d.status==='complete')break;if(d.status==='started'||d.status==='running'){url=ensureNextUrl(d);continue}break}}catch(e){document.getElementById('state').textContent='failed: '+e.message}finally{setControls(false)}}
async function startRun(){setControls(true);try{const d=await call('/tools/start-ovoko-category-mapping-autorun?token='+encodeURIComponent(token)+'&batch_size=100&sample_limit=50&only_missing_ovoko_category_mapping=1&include_ambiguous=1&continue_on_error=1','startOut');render(d,'startOut');if((d.status==='started'||d.status==='running')&&nextUrl){await autoTick(nextUrl)}}catch(e){document.getElementById('state').textContent='failed: '+e.message;setControls(false)}}
async function manualTick(){if(!currentRunId)return;const d=await call(nextUrl||tickUrl(),'tickOut');render(d,'tickOut')}
async function refreshStatus(){if(!currentRunId)return;const d=await call(statusUrl(),'tickOut');render(d,'tickOut')}
document.getElementById('start').onclick=startRun;document.getElementById('resume').onclick=startRun;document.getElementById('manualTick').onclick=manualTick;document.getElementById('refresh').onclick=refreshStatus;
</script></body></html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }


    public function ovokoCategoryMappingAutorunSmoke(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        return response()->json([
            'ok' => true,
            'smoke' => true,
            'controller_loaded' => true,
            'time' => now()->toISOString(),
            'commit' => $this->currentCommitHash(),
        ]);
    }

    public function debugOvokoCategoryMappingAutorunStartMinimal(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        return response()->json([
            'ok' => true,
            'dry_run' => true,
            'ovoko_write' => false,
            'local_update' => false,
            'step' => 'minimal_start_reached',
        ]);
    }

    public function appDeployDebug(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        return response()->json([
            'ok' => true,
            'app_env' => config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'php_version' => PHP_VERSION,
            'controller_exists' => class_exists(self::class),
            'routes_loaded' => Route::has('tools.start-ovoko-category-mapping-autorun')
                && Route::has('tools.ovoko-category-mapping-autorun-smoke')
                && Route::has('tools.debug-ovoko-category-mapping-autorun-start-minimal'),
            'commit' => $this->currentCommitHash(),
            'time' => now()->toISOString(),
        ]);
    }

    public function clearLaravelCache(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        if (! $request->boolean('confirm', false)) {
            return response()->json([
                'ok' => false,
                'error_message' => 'confirm=1 is required.',
            ], 422);
        }

        Artisan::call('optimize:clear');

        return response()->json([
            'ok' => true,
            'command' => 'optimize:clear',
            'output' => trim(Artisan::output()),
            'time' => now()->toISOString(),
        ]);
    }

    public function debugOvokoCategoryMappingStartSteps(Request $request): JsonResponse
    {
        $steps = [];
        $context = [];
        $failedStep = 'entered_method';

        try {
            $this->markDebugStep($steps, $failedStep, 'entered_method');

            if (! $this->validToken($request)) {
                return $this->invalidTokenResponse();
            }
            $this->markDebugStep($steps, $failedStep, 'token_checked');

            $params = $this->ovokoAutorunParams($request);
            $context = array_merge($context, $this->debuggableAutorunParams($params));
            $this->markDebugStep($steps, $failedStep, 'params_parsed');

            $context['cache_driver'] = (string) config('cache.default');
            $this->markDebugStep($steps, $failedStep, 'cache_checked');

            $activeId = Cache::get('ovoko_category_mapping_autorun_active');
            $this->markDebugStep($steps, $failedStep, 'active_run_checked');

            $active = null;
            if ($activeId) {
                $active = Cache::get($this->autorunCacheKey((string) $activeId));
                $context['cache_key'] = $this->autorunCacheKey((string) $activeId);
            }
            $this->markDebugStep($steps, $failedStep, 'active_run_loaded_or_none');

            $this->markDebugStep($steps, $failedStep, 'worklist_count_started');
            $worklistCount = $this->linkedOvokoPartsQuery((bool) $params['only_missing_ovoko_category_mapping'])->count();
            $context['worklist_count'] = $worklistCount;
            $this->markDebugStep($steps, $failedStep, 'worklist_count_done');

            $sample = $this->loadOvokoAutorunWorkItems((bool) $params['only_missing_ovoko_category_mapping'], 0, min(5, (int) $params['batch_size']));
            $this->markDebugStep($steps, $failedStep, 'worklist_sample_loaded');

            $runId = 'debug_ovoko_catmap_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
            $state = $this->initialOvokoAutorunState($runId, $params, $worklistCount, false, $sample);
            $this->markDebugStep($steps, $failedStep, 'state_initialized');

            $context['estimated_state_size_bytes'] = strlen(serialize($state));
            $this->markDebugStep($steps, $failedStep, 'state_size_estimated');

            $context['cache_key'] = $this->autorunCacheKey($runId);
            $this->markDebugStep($steps, $failedStep, 'cache_write_started');
            Cache::put($this->autorunCacheKey($runId), $state, now()->addMinutes(30));
            Cache::forget($this->autorunCacheKey($runId));
            $this->markDebugStep($steps, $failedStep, 'cache_write_done');

            $nextUrl = $this->autorunNextUrl($request, $runId);
            $this->markDebugStep($steps, $failedStep, 'next_url_built');

            $this->markDebugStep($steps, $failedStep, 'response_ready');

            return response()->json([
                'ok' => true,
                'dry_run' => true,
                'ovoko_write' => false,
                'local_update' => false,
                'completed_steps' => $steps,
                'debug_context' => $context + ['sample_count' => count($sample), 'active_run_present' => is_array($active)],
                'next_url_preview' => $nextUrl,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json($this->debugStartFailurePayload($failedStep, $steps, $exception, $context), 500);
        }
    }

    public function startOvokoCategoryMappingAutorun(Request $request): JsonResponse
    {
        try {
            $request->attributes->set('autorun_step', 'entered_method');

            if (! $this->validToken($request)) return $this->invalidTokenResponse();
            $request->attributes->set('autorun_step', 'token_checked');

            $params = $this->ovokoAutorunParams($request);
            $request->attributes->set('autorun_step', 'params_parsed');

            $activeId = Cache::get('ovoko_category_mapping_autorun_active');
            $request->attributes->set('autorun_step', 'cache_checked');
            if (! $request->boolean('force_new_run', false) && $activeId) {
                $active = Cache::get($this->autorunCacheKey((string) $activeId));
                $request->attributes->set('autorun_step', 'active_run_loaded_or_none');
                if (is_array($active) && in_array($active['status'] ?? null, ['started','running'], true)) {
                    return response()->json($this->autorunPublicStateWithNextUrl($active, $request) + ['active_run' => true, 'step' => 'active_run_checked']);
                }
            }
            $request->attributes->set('autorun_step', 'active_run_checked');

            $request->attributes->set('autorun_step', 'worklist_count_started');
            $total = $this->linkedOvokoPartsQuery((bool) $params['only_missing_ovoko_category_mapping'])->count();
            $request->attributes->set('autorun_step', 'worklist_count_done');

            $runId = 'ovoko_catmap_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
            $state = $this->initialOvokoAutorunState($runId, $params, $total, true);
            $request->attributes->set('autorun_step', 'state_initialized');

            $this->putAutorun($state);
            if ($total > 0) Cache::put('ovoko_category_mapping_autorun_active', $runId, now()->addDay());
            $request->attributes->set('autorun_step', 'cache_write_done');

            $response = $this->autorunPublicStateWithNextUrl($state, $request);
            $request->attributes->set('autorun_step', 'next_url_built');
            $response['step'] = 'response_ready';
            $response['debug_steps'] = ['entered_method','token_checked','params_parsed','cache_checked','active_run_checked','worklist_count_started','worklist_count_done','state_initialized','cache_write_done','next_url_built','response_ready'];

            return response()->json($response);
        } catch (Throwable $exception) {
            return $this->safeAutorunExceptionJson($request, 'start-ovoko-category-mapping-autorun', $exception);
        }
    }

    public function runOvokoCategoryMappingAutorun(Request $request): JsonResponse
    {
        return $this->safeAutorunJson($request, 'run-ovoko-category-mapping-autorun', function () use ($request): JsonResponse {
            if (! $this->validToken($request)) return $this->invalidTokenResponse();
            return $this->runOvokoCategoryMappingAutorunUnsafe($request);
        });
    }

    private function runOvokoCategoryMappingAutorunUnsafe(Request $request): JsonResponse
    {
        $started = microtime(true);
        $runId = (string) $request->query('run_id', ''); $state = Cache::get($this->autorunCacheKey($runId));
        if (! $state) return response()->json(['ok'=>false,'error_message'=>'run_id not found'], 404);
        if (($state['status'] ?? null) === 'complete' || ($state['status'] ?? null) === 'failed') return response()->json($this->autorunPublicState($state));

        $state['status'] = 'running';
        $limitSeconds = max(1, min(15, (int) $request->query('tick_time_limit', $state['params']['tick_time_limit'] ?? 10)));
        $batchSize = max(1, min(100, (int) $request->query('batch_size', $state['params']['batch_size'] ?? 100)));
        $pagesPerTick = max(1, min(5, (int) $request->query('snapshot_pages_per_tick', $state['params']['snapshot_pages_per_tick'] ?? 3)));
        $sampleLimit = (int) ($state['params']['sample_limit'] ?? 50);
        $errors = [];
        $snapshotPagesProcessed = 0;
        $mapped = 0;
        if (($state['stage'] ?? 'category_tree') === 'category_tree') {
            $result = ['sample_errors'=>[], 'warnings'=>[]];
            $state['category_tree'] = $this->fetchOvokoCategoryTree($result, max(1, min(10, $limitSeconds)));
            $errors = array_merge($errors, $result['sample_errors'] ?? []);
            $state['warnings'] = array_values(array_unique(array_merge($state['warnings'] ?? [], $result['warnings'] ?? [])));
            $state['stage'] = 'ovoko_snapshot';
        }

        if (($state['stage'] ?? null) === 'ovoko_snapshot') {
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            $wantedIds = $state['wanted_ovoko_ids'] ?? [];
            if ($wantedIds === [] && empty($state['work_items_loaded'])) {
                $previewItems = $this->loadOvokoAutorunWorkItems((bool) ($state['params']['only_missing_ovoko_category_mapping'] ?? true), (int) ($state['current_offset'] ?? 0), (int) ($state['params']['batch_size'] ?? 100));
                $wantedIds = array_values(array_unique(array_map('strval', array_column($previewItems, 'ovoko_part_id'))));
            }
            $wantedSet = array_flip(array_map('strval', $wantedIds));
            if (! $client instanceof OvokoApiClient) {
                $errors[] = ['type' => 'ovoko_client_unavailable'];
                $state['stage'] = 'mapping';
            } else {
                while ($snapshotPagesProcessed < $pagesPerTick && microtime(true) - $started < max(1, $limitSeconds - 1)) {
                    $page = (int) ($state['snapshot']['page'] ?? 1);
                    $api = $client->fetchPartsPage($page, OvokoApiClient::MAX_PARTS_PAGE_LIMIT, max(1, min(10, $limitSeconds)));
                    $snapshotPagesProcessed++;
                    $state['snapshot']['pages_processed'] = (int) ($state['snapshot']['pages_processed'] ?? 0) + 1;
                    if (! ($api['api_ok'] ?? false)) {
                        if ((int) ($api['http_status'] ?? 0) === 520) $api = $client->fetchPartsPage($page, OvokoApiClient::MAX_PARTS_PAGE_LIMIT, max(1, min(10, $limitSeconds)));
                        if (! ($api['api_ok'] ?? false)) {
                            $state['snapshot']['failed_pages'][] = $page;
                            $errors[] = ['type'=>'ovoko_parts_api_status_not_ok','page'=>$page,'http_status'=>$api['http_status'] ?? null,'api_status_code'=>$api['api_status_code'] ?? null,'error'=>$api['error'] ?? null];
                            $state['snapshot']['page'] = $page + 1;
                            continue;
                        }
                    }
                    $rows = $api['parts'] ?? [];
                    foreach ($rows as $row) {
                        if (! is_array($row)) continue;
                        $state['snapshot']['total_seen'] = (int) ($state['snapshot']['total_seen'] ?? 0) + 1;
                        $row['snapshot_page'] = $page;
                        foreach ($this->ovokoSnapshotMatchIds($row) as $id) if (isset($wantedSet[$id])) $state['snapshot']['parts_by_id'][$id] = $row;
                    }
                    $state['snapshot']['matched_linked_ids_count'] = count($state['snapshot']['parts_by_id'] ?? []);
                    $state['snapshot']['page'] = $page + 1;
                    if (count($rows) < OvokoApiClient::MAX_PARTS_PAGE_LIMIT || $state['snapshot']['matched_linked_ids_count'] >= count($wantedSet)) { $state['snapshot']['complete'] = true; $state['stage'] = 'mapping'; break; }
                }
            }
        }

        if (($state['stage'] ?? null) === 'mapping' && microtime(true) - $started < $limitSeconds) {
            $offset = (int) ($state['current_offset'] ?? 0);
            $items = empty($state['work_items_loaded'])
                ? $this->loadOvokoAutorunWorkItems((bool) ($state['params']['only_missing_ovoko_category_mapping'] ?? true), $offset, $batchSize)
                : array_slice($state['work_items'] ?? [], $offset, $batchSize);
            $partIds = array_column($items, 'part_id');
            $parts = Part::query()->with(['category','marketplaceListings'])->whereIn('id', $partIds)->get()->keyBy('id');
            $tree = $state['category_tree'] ?? ['categories'=>[], 'by_id'=>[]];
            $result = ['sample_errors'=>[], 'warnings'=>[]];
            foreach ($items as $item) {
                if (microtime(true) - $started >= $limitSeconds) break;
                $mapped++;
                $part = $parts->get($item['part_id']);
                if (! $part) continue;
                $ovokoId = (string) $item['ovoko_part_id'];
                $ovoko = $state['snapshot']['parts_by_id'][$ovokoId] ?? null;
                $category = $ovoko ? $this->extractOvokoCategory($ovoko, $tree, $result) : null;
                $key = (string) $part->category_id;
                if (! $category || blank($category['ovoko_category_id'])) { $state['no_evidence'][$key] ??= ['local_category_id'=>$part->category_id,'local_category_path'=>$part->category?->category_path ?? $part->category?->name,'sample_parts'=>[]]; $this->pushSample($state['no_evidence'][$key]['sample_parts'], $this->linkedProductSample($part,$ovokoId,$category), $sampleLimit); continue; }
                $state['groups'][$key] ??= $this->localCategoryGroup($part); $catKey=(string)$category['ovoko_category_id']; $state['groups'][$key]['observed_ovoko_categories'][$catKey] ??= $category + ['count'=>0,'sample_part_ids'=>[],'sample_ovoko_part_ids'=>[]]; $state['groups'][$key]['observed_ovoko_categories'][$catKey]['count']++; $this->pushSample($state['groups'][$key]['observed_ovoko_categories'][$catKey]['sample_part_ids'], ['part_id'=>$part->id], $sampleLimit); $this->pushSample($state['groups'][$key]['observed_ovoko_categories'][$catKey]['sample_ovoko_part_ids'], ['ovoko_part_id'=>$ovokoId], $sampleLimit);
            }
            $state['processed_count'] = min((int)$state['processed_total'], $offset + $mapped); $state['current_offset'] = $offset + $mapped;
            if ($state['processed_count'] >= $state['processed_total']) $state['stage'] = 'complete';
        }

        foreach ($errors as $e) $this->pushSample($state['sample_errors'], $e, $sampleLimit);
        $state['time_spent_seconds'] = round(microtime(true) - $started, 3);
        $state['current_batch'] = ['stage'=>$state['stage'],'offset'=>(int)($state['current_offset'] ?? 0),'processed'=>$mapped,'snapshot_pages_processed'=>$snapshotPagesProcessed,'errors'=>$errors];
        if (($state['stage'] ?? null) === 'complete') { $state['status']='complete'; $state['completed_at']=now()->toISOString(); Cache::forget('ovoko_category_mapping_autorun_active'); }
        $state['updated_at']=now()->toISOString(); $this->putAutorun($state); return response()->json($this->autorunPublicStateWithNextUrl($state, $request));
    }

    public function resetOvokoCategoryMappingAutorun(Request $request): JsonResponse
    {
        return $this->safeAutorunJson($request, 'reset-ovoko-category-mapping-autorun', function () use ($request): JsonResponse {
            if (! $this->validToken($request)) return $this->invalidTokenResponse();
            if (! $request->boolean('confirm')) return response()->json(['ok'=>false,'error_message'=>'confirm=1 is required'], 422);
            $runId = (string) $request->query('run_id', '');
            if ($runId !== '') Cache::forget($this->autorunCacheKey($runId));
            if (Cache::get('ovoko_category_mapping_autorun_active') === $runId || $runId === '') Cache::forget('ovoko_category_mapping_autorun_active');
            return response()->json(['ok'=>true,'reset'=>true,'run_id'=>$runId ?: null]);
        });
    }

    public function statusOvokoCategoryMappingAutorun(Request $request): JsonResponse
    {
        return $this->safeAutorunJson($request, 'status-ovoko-category-mapping-autorun', function () use ($request): JsonResponse {
            if (! $this->validToken($request)) return $this->invalidTokenResponse();
            $state=Cache::get($this->autorunCacheKey((string)$request->query('run_id','')));
            return $state ? response()->json($this->autorunPublicState($state)) : response()->json(['ok'=>false,'error_message'=>'run_id not found'],404);
        });
    }

    public function resultsOvokoCategoryMappingAutorun(Request $request): JsonResponse
    {
        return $this->safeAutorunJson($request, 'results-ovoko-category-mapping-autorun', function () use ($request): JsonResponse {
            if (! $this->validToken($request)) return $this->invalidTokenResponse();
            $state=Cache::get($this->autorunCacheKey((string)$request->query('run_id','')));
            return $state ? response()->json($this->autorunPublicState($state)) : response()->json(['ok'=>false,'error_message'=>'run_id not found'],404);
        });
    }

    public function debugOvokoLinkedProductRawFields(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $limit = max(1, min(50, (int) $request->query('limit', 10)));
        $defaultIds = ['10776', '10686', '10647', '10500', '10489'];
        $ids = collect(explode(',', (string) $request->query('ovoko_part_ids', implode(',', $defaultIds))))
            ->map(fn (string $id) => trim($id))->filter()->unique()->take($limit)->values()->all();

        $result = [
            'ok' => true,
            'dry_run' => true,
            'ovoko_write' => false,
            'local_update' => false,
            'requested_ovoko_part_ids' => $ids,
            'checked_count' => 0,
            'products' => [],
            'sample_errors' => [],
            'warnings' => ['read_only_diagnostics_only_no_ovoko_allegro_ebay_or_local_writes'],
        ];

        try {
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            foreach ($ids as $id) {
                $result['checked_count']++;
                $detail = $client instanceof OvokoApiClient ? $client->fetchPartRawById($id) : ['api_ok' => false, 'error' => 'ovoko_client_unavailable'];
                $raw = is_array($detail['raw'] ?? null) ? $detail['raw'] : [];
                $normalized = is_array($detail['normalized'] ?? null) ? $detail['normalized'] : [];

                $result['products'][] = [
                    'ovoko_part_id' => $id,
                    'found_in_ovoko_response' => (bool) (($detail['api_ok'] ?? false) && $raw !== []),
                    'endpoint_used' => $detail['endpoint_used'] ?? null,
                    'http_status' => $detail['http_status'] ?? null,
                    'api_status_code' => $detail['api_status_code'] ?? null,
                    'response_top_level_keys' => $detail['response_top_level_keys'] ?? [],
                    'raw_top_level_keys' => array_values(array_slice(array_keys($raw), 0, 80)),
                    'category_like_fields' => $this->categoryLikeFields($raw),
                    'has_category_id' => filled(data_get($raw, 'category_id')) || filled(data_get($normalized, 'ovoko_category_id')),
                    'category_id' => data_get($raw, 'category_id') ?? data_get($normalized, 'ovoko_category_id'),
                    'has_category_title_path' => filled(data_get($raw, 'category_title_path')) || filled(data_get($normalized, 'ovoko_category_path')),
                    'category_title_path' => data_get($raw, 'category_title_path') ?? data_get($normalized, 'ovoko_category_path'),
                    'has_part' => array_key_exists('part', $raw),
                    'has_category' => array_key_exists('category', $raw),
                    'has_type' => array_key_exists('type', $raw),
                    'has_group' => array_key_exists('group', $raw),
                    'has_category_tree' => array_key_exists('category_tree', $raw),
                    'normalized_category' => $this->extractOvokoCategory($normalized, null, $result),
                    'trimmed_raw_payload' => $this->trimRawPayload($raw),
                    'error' => $detail['error'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            $result['sample_errors'][] = ['type' => 'ovoko_api_exception', 'message' => $e->getMessage()];
        }

        return response()->json($result);
    }

    public function debugOvokoPartDetailEndpoints(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $ovokoPartId = trim((string) $request->query('ovoko_part_id', ''));
        if ($ovokoPartId === '') return response()->json(['ok' => false, 'error_message' => 'ovoko_part_id is required.'], 422);

        $result = [
            'ok' => true,
            'dry_run' => true,
            'ovoko_write' => false,
            'local_update' => false,
            'ovoko_read_request' => true,
            'ovoko_part_id' => $ovokoPartId,
            'tested_endpoints' => [],
            'best_match' => null,
            'warnings' => ['read_only_diagnostics_only_no_ovoko_allegro_ebay_or_local_writes'],
        ];

        try {
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            if (! $client instanceof OvokoApiClient) {
                $result['warnings'][] = 'ovoko_client_unavailable';
                return response()->json($result);
            }

            foreach ($client->comparePartDetailEndpoints($ovokoPartId) as $candidate) {
                $public = $this->publicEndpointDiagnostic($candidate);
                $result['tested_endpoints'][] = $public;
                if ($result['best_match'] === null && ($public['matched_requested_id'] ?? false)) $result['best_match'] = $public;
                if (($public['api_status_code'] ?? null) === 'R200' && ! ($public['matched_requested_id'] ?? false)) $result['warnings'][] = 'endpoint_returned_success_but_not_requested_part_id';
            }
            $result['warnings'] = array_values(array_unique($result['warnings']));
        } catch (\Throwable $e) {
            $result['warnings'][] = 'ovoko_api_exception';
            $result['tested_endpoints'][] = ['endpoint' => null, 'request_fields' => [], 'http_status' => null, 'api_status_code' => null, 'matched_requested_id' => false, 'returned_raw_id' => null, 'returned_external_id' => null, 'returned_name' => null, 'returned_category_id' => null, 'returned_shop_url' => null, 'top_level_keys' => [], 'error' => $e->getMessage()];
        }

        return response()->json($result);
    }

    public function debugOvokoFindLinkedPartInSnapshot(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $requestedId = trim((string) $request->query('ovoko_part_id', ''));
        if ($requestedId === '') return response()->json(['ok' => false, 'error_message' => 'ovoko_part_id is required.'], 422);

        $result = [
            'ok' => true,
            'dry_run' => true,
            'ovoko_write' => false,
            'local_update' => false,
            'ovoko_read_request' => true,
            'requested_ovoko_part_id' => $requestedId,
            'found' => false,
            'pages_checked' => 0,
            'total_seen' => 0,
            'matched_part' => null,
            'sample_ids' => [],
            'warnings' => ['read_only_snapshot_diagnostics_no_ovoko_allegro_ebay_or_local_writes'],
        ];

        $tree = $this->fetchOvokoCategoryTree($result);
        $snapshot = $this->fetchOvokoPartsSnapshotByIds([$requestedId], $result, false);
        $result['pages_checked'] = $snapshot['pages_checked'];
        $result['total_seen'] = $snapshot['total_seen'];
        $result['sample_ids'] = $snapshot['sample_ids'];
        $result['warnings'] = array_values(array_unique(array_merge($result['warnings'], $snapshot['warnings'])));

        $part = $snapshot['parts_by_id'][$requestedId] ?? null;
        if ($part) {
            $category = $this->extractOvokoCategory($part, $tree, $result);
            $result['found'] = true;
            $result['matched_part'] = [
                'page' => $part['snapshot_page'] ?? null,
                'raw_id' => $part['raw_id'] ?? null,
                'external_id' => $part['external_offer_id'] ?? null,
                'name' => $part['title'] ?? null,
                'category_id' => $category['ovoko_category_id'] ?? ($part['ovoko_category_id'] ?? null),
                'category_id_resolved' => (bool) (($category['ovoko_category_id'] ?? null) && isset($tree['by_id'][(string) $category['ovoko_category_id']])),
                'category_path_pl' => $category['ovoko_category_path'] ?? null,
                'shop_url' => $part['url'] ?? null,
                'top_level_keys' => $part['raw_top_level_keys'] ?? [],
            ];
        } else {
            $result['warnings'][] = 'requested_id_not_found_in_ovoko_parts_snapshot';
            $result['snapshot_contains_requested_id_as_string'] = in_array($requestedId, array_map('strval', array_column($snapshot['sample_ids'], 'id')), true);
            $result['snapshot_contains_requested_id_as_int'] = ctype_digit($requestedId) && in_array((int) $requestedId, array_column($snapshot['sample_ids'], 'id'), true);
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




    private function categoryLikeFields(array $raw): array
    {
        $out = [];
        $walk = function ($value, string $path) use (&$walk, &$out): void {
            if (! is_array($value)) return;
            foreach ($value as $key => $child) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;
                if (preg_match('/(category|part|type|group|tree)/i', (string) $key)) {
                    $out[$childPath] = is_scalar($child) || $child === null ? $child : $this->trimRawPayload($child, 8);
                }
                if (is_array($child) && count($out) < 120) $walk($child, $childPath);
            }
        };
        $walk($raw, '');
        return $out;
    }

    private function trimRawPayload(array $raw, int $limit = 40): array
    {
        $blocked = ['username', 'password', 'user_token', 'token', 'api_key', 'authorization'];
        $out = [];
        foreach (array_slice($raw, 0, $limit, true) as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) continue;
            $out[$key] = is_array($value) ? $this->trimRawPayload($value, 20) : $value;
        }
        return $out;
    }

    private function publicEndpointDiagnostic(array $candidate): array
    {
        return [
            'endpoint' => $candidate['endpoint'] ?? null,
            'request_fields' => $candidate['request_fields'] ?? [],
            'http_status' => $candidate['http_status'] ?? null,
            'api_status_code' => $candidate['api_status_code'] ?? null,
            'matched_requested_id' => (bool) ($candidate['matched_requested_id'] ?? false),
            'returned_raw_id' => $candidate['returned_raw_id'] ?? null,
            'returned_external_id' => $candidate['returned_external_id'] ?? null,
            'returned_name' => $candidate['returned_name'] ?? null,
            'returned_category_id' => $candidate['returned_category_id'] ?? null,
            'returned_shop_url' => $candidate['returned_shop_url'] ?? null,
            'top_level_keys' => $candidate['top_level_keys'] ?? [],
            'error' => $candidate['error'] ?? null,
        ];
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

    private function fetchOvokoPartsSnapshotByLinkedIds($parts, array &$result): array
    {
        $ids = $parts->map(fn ($part) => $this->listingOvokoExternalId($this->ovokoListing($part)))
            ->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
        if ($ids === []) return [];

        return $this->fetchOvokoPartsSnapshotByIds($ids, $result, true)['parts_by_id'];
    }

    private function fetchOvokoPartsSnapshotByIds(array $ids, array &$result, bool $stopWhenAllFound = true): array
    {
        $wanted = array_values(array_unique(array_map('strval', $ids)));
        $wantedSet = array_flip($wanted);
        $partsById = [];
        $warnings = [];
        $sampleIds = [];
        $pagesChecked = 0;
        $totalSeen = 0;
        $snapshotComplete = false;
        $maxPages = 200;
        $limit = OvokoApiClient::MAX_PARTS_PAGE_LIMIT;

        try {
            $client = app(MarketplaceApiManager::class)->client('ovoko');
            if (! $client instanceof OvokoApiClient) {
                return ['parts_by_id' => [], 'pages_checked' => 0, 'total_seen' => 0, 'sample_ids' => [], 'warnings' => ['ovoko_client_unavailable']];
            }

            for ($page = 1; $page <= $maxPages; $page++) {
                $api = null;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    $api = $client->fetchPartsPage($page, $limit);
                    if (($api['api_ok'] ?? false) || (int) ($api['http_status'] ?? 0) !== 520) break;
                    $warnings[] = 'ovoko_parts_api_http_520_retry';
                    usleep(250000);
                }
                $pagesChecked++;
                if (! ($api['api_ok'] ?? false)) {
                    $warnings[] = 'ovoko_parts_api_status_not_ok';
                    $result['sample_errors'][] = ['type' => 'ovoko_parts_api_status_not_ok', 'page' => $page, 'http_status' => $api['http_status'] ?? null, 'api_status_code' => $api['api_status_code'] ?? null, 'error' => $api['error'] ?? null];
                    break;
                }

                $rows = $api['parts'] ?? [];
                foreach ($rows as $row) {
                    if (! is_array($row)) continue;
                    $totalSeen++;
                    $row['snapshot_page'] = $page;
                    foreach ($this->ovokoSnapshotMatchIds($row) as $id) {
                        $this->pushSample($sampleIds, ['id' => $id, 'page' => $page], 50);
                        if (isset($wantedSet[$id])) $partsById[$id] = $row;
                    }
                }

                if ($stopWhenAllFound && count(array_intersect($wanted, array_keys($partsById))) === count($wanted)) break;
                if (count($rows) < $limit) { $snapshotComplete = true; break; }
            }
        } catch (\Throwable $e) {
            $warnings[] = 'ovoko_parts_snapshot_exception';
            $result['sample_errors'][] = ['type' => 'ovoko_parts_snapshot_exception', 'message' => $e->getMessage()];
        }

        foreach (array_diff($wanted, array_keys($partsById)) as $missingId) {
            $type = $snapshotComplete ? 'ovoko_part_not_found_in_snapshot' : 'snapshot_incomplete_cannot_confirm_missing_id';
            if (! $snapshotComplete) $warnings[] = 'snapshot_incomplete_cannot_confirm_missing_id';
            $this->pushSample($result['sample_errors'], ['type' => $type, 'ovoko_part_id' => $missingId], 50);
        }

        return ['parts_by_id' => $partsById, 'pages_checked' => $pagesChecked, 'total_seen' => $totalSeen, 'sample_ids' => $sampleIds, 'warnings' => array_values(array_unique($warnings))];
    }

    private function ovokoSnapshotMatchIds(array $row): array
    {
        $ids = [];
        foreach (['raw_id', 'external_offer_id', 'external_id_raw', 'part_id_raw', 'ovoko_part_id_raw', 'rrr_id_raw'] as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') $ids[] = (string) $value;
        }

        return array_values(array_unique($ids));
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

    private function fetchOvokoCategoryTree(array &$result, int $timeoutSeconds = 30): array
    {
        try { $api = app(MarketplaceApiManager::class)->client('ovoko')->fetchCategories($timeoutSeconds); }
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


    private function ovokoCategoryIndexes(array $tree): array
    {
        $level3 = []; $exact = []; $normalized = []; $leaf = [];
        foreach ($tree['categories'] as $category) {
            if ((int) ($category['level'] ?? 0) !== 3) continue;
            $path = $this->ovokoCategoryPath($category, $tree['by_id'], 'pl');
            if (! filled($path)) continue;
            $item = $category + ['full_path_pl' => $path];
            $level3[] = $item;
            $exact[(string) $path] = $item;
            $normalized[$this->normalizeCategoryPath($path)][] = $item;
            $leaf[$this->normalizeCategoryPath((string) ($category['pl'] ?? $category['en'] ?? ''))][] = $item;
        }
        return ['level3'=>$level3,'exact'=>$exact,'normalized'=>$normalized,'leaf'=>$leaf];
    }

    private function includePathMappingForOnly(string $matchType, string $only): bool
    {
        if ($only === 'all' || $only === 'matched') return true;
        return match ($only) {
            'exact_path' => str_contains($matchType, 'exact_path_match'),
            'normalized_path' => $matchType === 'normalized_path_match',
            'exact_name' => $matchType === 'exact_name_match',
            default => false,
        };
    }

    private function pathMappingResponse(array $result, string $format, string $only)
    {
        if ($format !== 'csv') return response()->json($result);

        $rows = $only === 'unmatched' ? ($result['unmatched_categories'] ?? []) : ($result['preview_mappings'] ?? []);
        $columns = $only === 'unmatched'
            ? ['local_category_id', 'local_category_name', 'local_category_path', 'local_parts_count', 'reason']
            : ['local_category_id', 'local_category_name', 'local_category_path', 'ovoko_category_id', 'ovoko_category_name', 'ovoko_category_path', 'ovoko_level', 'match_type', 'confidence', 'local_parts_count', 'linked_products_evidence_count'];
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);
        foreach ($rows as $row) fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? null, $columns));
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ovoko-category-path-mapping-'.$only.'.csv"',
        ]);
    }

    private function matchLocalCategoryToOvokoPath(array $local, array $ovoko): array
    {
        $path = (string) ($local['category_path'] ?? $local['name'] ?? '');
        $name = (string) ($local['name'] ?? $this->leafName($path));
        if (isset($ovoko['exact'][$path])) return ['match_type'=>'exact_path_match','category'=>$ovoko['exact'][$path]];
        $normalizedPath = $this->normalizeCategoryPath($path);
        $pathMatches = $ovoko['normalized'][$normalizedPath] ?? [];
        if (count($pathMatches) === 1) return ['match_type'=>'normalized_path_match','category'=>$pathMatches[0]];
        $leafMatches = $ovoko['leaf'][$this->normalizeCategoryPath($name)] ?? [];
        if (count($leafMatches) === 1) return ['match_type'=>'exact_name_match','category'=>$leafMatches[0]];
        if (count($leafMatches) > 1) return ['match_type'=>'ambiguous_name_match','sample'=>['local_category_id'=>(int)$local['id'],'local_category_name'=>$name,'local_category_path'=>$path,'candidate_count'=>count($leafMatches),'candidates'=>array_map(fn($c)=>['ovoko_category_id'=>(string)$c['id'],'ovoko_category_name'=>$c['pl'] ?? $c['en'] ?? null,'ovoko_category_path'=>$c['full_path_pl'],'ovoko_level'=>$c['level'] ?? null], array_slice($leafMatches,0,10))]];
        return ['match_type'=>'unmatched'];
    }

    private function pathMappingPreview(array $local, array $match, int $partsCount, ?array $linkedConsensus): array
    {
        $category = $match['category'];
        $confidence = $match['match_type'] === 'exact_name_match' ? 'medium' : 'high';
        $matchType = $match['match_type'];
        $evidence = $linkedConsensus['evidence_count'] ?? null;
        if ($linkedConsensus && (string)($linkedConsensus['ovoko_category_id'] ?? '') === (string)$category['id'] && $matchType === 'exact_path_match') {
            $confidence = 'very_high';
            $matchType = 'linked_products_consensus + exact_path_match';
        }
        return ['local_category_id'=>(int)$local['id'],'local_category_name'=>$local['name'] ?? null,'local_category_path'=>$local['category_path'] ?? ($local['name'] ?? null),'ovoko_category_id'=>(string)$category['id'],'ovoko_category_name'=>$category['pl'] ?? $category['en'] ?? null,'ovoko_category_path'=>$category['full_path_pl'],'ovoko_level'=>$category['level'] ?? null,'match_type'=>$matchType,'confidence'=>$confidence,'local_parts_count'=>$partsCount,'linked_products_evidence_count'=>$evidence];
    }

    private function linkedConsensusMappingsFromAutorun(Request $request, int $sampleLimit): array
    {
        $runId = (string) $request->query('run_id', '');
        if ($runId === '') return [];
        $state = Cache::get($this->autorunCacheKey($runId));
        if (! is_array($state)) return [];
        $final = $this->finalizeAutorunMappings($state, $sampleLimit);
        $out = [];
        foreach ($final['suggested_mappings'] ?? [] as $mapping) $out[(string)$mapping['local_category_id']] = $mapping;
        return $out;
    }

    private function normalizeCategoryPath(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(['/', '\\', '›', '»', '→', '|'], '>', $value);
        $value = preg_replace('/\s*>\s*/u', ' > ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return mb_strtolower($value);
    }

    private function matchOvokoTreeCategoryToLocal(array $category, $mappingByOvokoId, $externalIdIndex, $exactPathIndex, $normalizedPathIndex, $localsById): array
    {
        $id = (string) $category['id'];
        $mappingRows = $mappingByOvokoId->get($id, collect());
        $mappedLocalIds = collect($mappingRows)->pluck('local_category_id')->filter()->map(fn ($value): string => (string) $value)->unique()->values();
        if ($mappedLocalIds->count() === 1) {
            $local = $localsById->get($mappedLocalIds->first());
            if ($local) return ['match_type' => 'existing_mapping_match', 'local' => $local];
        }
        if ($mappedLocalIds->count() > 1) return $this->ambiguousTreeSyncMatch($category, 'existing_mapping_match', $mappedLocalIds->all());

        foreach ([
            'external_id_match' => $externalIdIndex->get($id, collect()),
            'exact_path_match' => $exactPathIndex->get((string) $category['full_path'], collect()),
            'normalized_path_match' => $normalizedPathIndex->get($this->normalizeTreeSyncPath((string) $category['full_path']), collect()),
        ] as $type => $matches) {
            $matches = collect($matches)->values();
            if ($matches->count() === 1) return ['match_type' => $type, 'local' => $matches->first()];
            if ($matches->count() > 1) return $this->ambiguousTreeSyncMatch($category, $type, $matches->pluck('id')->all());
        }

        return ['match_type' => null, 'local' => null];
    }

    private function ambiguousTreeSyncMatch(array $category, string $matchType, array $localIds): array
    {
        return [
            'ambiguous' => true,
            'local' => null,
            'sample' => [
                'ovoko_category_id' => $category['id'],
                'ovoko_name' => $category['name'],
                'ovoko_path' => $category['full_path'],
                'match_type' => $matchType,
                'candidate_local_category_ids' => array_values(array_slice($localIds, 0, 20)),
                'reason' => 'more_than_one_local_category_candidate',
            ],
        ];
    }

    private function normalizeTreeSyncPath(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = preg_split('/\s+>\s+/u', $value) ?: [$value];
        $parts = array_map(fn (string $part): string => preg_replace('/\s+/u', ' ', trim($part)) ?? trim($part), $parts);
        return mb_strtolower(implode(' > ', array_filter($parts, fn (string $part): bool => $part !== '')));
    }

    private function pathLevel(string $path): int
    {
        if ($path === '') return 1;
        return count(preg_split('/\s+>\s+/u', $path) ?: [$path]);
    }

    private function leafName(string $path): string
    {
        $parts = preg_split('/\s*>\s*|\/|\\|›|»|→|\|/u', $path) ?: [$path];
        return trim((string) end($parts));
    }

    private function isUncategorizedCategoryValue(string $value): bool
    {
        return $this->normalizeCategoryPath($value) === 'bez kategorii';
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
        return ['ok' => true, 'dry_run' => true, 'ovoko_write' => false, 'local_update' => false, 'ovoko_read_request' => true, 'page' => $page, 'limit' => $limit, 'linked_products_checked' => 0, 'linked_products_with_ovoko_category' => 0, 'local_categories_observed_count' => 0, 'suggested_mapping_count' => 0, 'high_confidence_mapping_count' => 0, 'medium_confidence_mapping_count' => 0, 'ambiguous_mapping_count' => 0, 'no_evidence_count' => 0, 'unmapped_or_missing_category_count' => 0, 'skipped_uncategorized_count' => 0, 'suggested_mappings' => [], 'ambiguous_mappings' => [], 'sample_high_confidence_mappings' => [], 'sample_products_without_ovoko_category' => [], 'sample_errors' => [], 'warnings' => ['read_only_dry_run_no_ovoko_allegro_ebay_or_local_writes']];
    }





    private function ovokoAutorunParams(Request $request): array
    {
        return [
            'batch_size' => max(1, min(100, (int) $request->query('batch_size', 100))),
            'sample_limit' => max(1, min(100, (int) $request->query('sample_limit', 50))),
            'tick_time_limit' => max(1, min(15, (int) $request->query('tick_time_limit', 10))),
            'snapshot_pages_per_tick' => max(1, min(5, (int) $request->query('snapshot_pages_per_tick', 3))),
            'only_missing_ovoko_category_mapping' => $request->boolean('only_missing_ovoko_category_mapping', true),
            'include_ambiguous' => $request->boolean('include_ambiguous', true),
            'continue_on_error' => $request->boolean('continue_on_error', true),
        ];
    }

    private function debuggableAutorunParams(array $params): array
    {
        return [
            'batch_size' => (int) $params['batch_size'],
            'sample_limit' => (int) $params['sample_limit'],
            'only_missing_ovoko_category_mapping' => (bool) $params['only_missing_ovoko_category_mapping'],
            'include_ambiguous' => (bool) $params['include_ambiguous'],
            'continue_on_error' => (bool) $params['continue_on_error'],
        ];
    }

    private function initialOvokoAutorunState(string $runId, array $params, int $total, bool $persistedRun, array $sampleWorkItems = []): array
    {
        return [
            'run_id' => $runId,
            'status' => $total > 0 ? 'started' : 'complete',
            'stage' => $total > 0 ? 'category_tree' : 'complete',
            'params' => $params,
            'work_items' => $sampleWorkItems,
            'work_items_loaded' => false,
            'wanted_ovoko_ids' => [],
            'processed_count' => 0,
            'processed_total' => $total,
            'current_offset' => 0,
            'groups' => [],
            'no_evidence' => [],
            'sample_errors' => [],
            'snapshot' => ['page'=>1,'pages_processed'=>0,'total_seen'=>0,'matched_linked_ids_count'=>0,'failed_pages'=>[],'complete'=>false,'parts_by_id'=>[]],
            'category_tree' => null,
            'current_batch' => ['stage'=>$total > 0 ? 'category_tree' : 'complete','offset'=>0,'processed'=>0,'snapshot_pages_processed'=>0,'errors'=>[]],
            'warnings' => ['read_only_autorun_no_ovoko_allegro_ebay_or_marketplace_category_mapping_writes'],
            'started_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
            'completed_at' => null,
            'time_spent_seconds' => 0,
            'state_storage' => $persistedRun ? 'params_and_offset_no_full_worklist' : 'debug_ephemeral',
        ];
    }

    private function loadOvokoAutorunWorkItems(bool $onlyMissing, int $offset, int $limit): array
    {
        return $this->linkedOvokoPartsQuery($onlyMissing)->skip($offset)->limit($limit)->get()->map(function (Part $part): array {
            return [
                'part_id' => (int) $part->id,
                'local_category_id' => $part->category_id !== null ? (int) $part->category_id : null,
                'ovoko_part_id' => (string) $this->listingOvokoExternalId($this->ovokoListing($part)),
            ];
        })->filter(fn (array $item) => filled($item['ovoko_part_id']))->values()->all();
    }

    private function markDebugStep(array &$steps, string &$failedStep, string $step): void
    {
        $failedStep = $step;
        $steps[] = $step;
    }

    private function debugStartFailurePayload(string $failedStep, array $completedSteps, Throwable $exception, array $context): array
    {
        return [
            'ok' => false,
            'dry_run' => true,
            'ovoko_write' => false,
            'local_update' => false,
            'failed_step' => $failedStep,
            'completed_steps' => $completedSteps,
            'exception_class' => get_class($exception),
            'safe_error_message' => $this->safeExceptionMessage($exception),
            'debug_context' => $context,
        ];
    }

    private function safeExceptionMessage(Throwable $exception): string
    {
        $safeMessage = $exception instanceof \PDOException ? 'database_error' : $exception->getMessage();
        return $safeMessage !== '' ? mb_substr($safeMessage, 0, 300) : class_basename($exception);
    }

    private function safeAutorunExceptionJson(Request $request, string $endpoint, Throwable $exception): JsonResponse
    {
        report($exception);
        $safeMessage = $this->safeExceptionMessage($exception);
        $payload = [
            'ok' => false,
            'dry_run' => true,
            'ovoko_write' => false,
            'local_update' => false,
            'status' => 'failed',
            'error' => class_basename($exception),
            'warnings' => [],
            'endpoint' => $endpoint,
            'run_id' => (string) $request->query('run_id', '') ?: null,
            'stage' => null,
            'step' => $request->attributes->get('autorun_step'),
            'safe_error_message' => $safeMessage,
            'exception_class' => get_class($exception),
        ];

        if (config('app.debug') || $this->validToken($request)) {
            $payload['file'] = $this->redactSecretsFromPath($exception->getFile());
            $payload['line'] = $exception->getLine();
        }

        return response()->json($payload, 500);
    }

    private function currentCommitHash(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_readable($head)) return null;

        $ref = trim((string) file_get_contents($head));
        if (str_starts_with($ref, 'ref: ')) {
            $refPath = base_path('.git/'.substr($ref, 5));
            return is_readable($refPath) ? trim((string) file_get_contents($refPath)) : null;
        }

        return $ref !== '' ? $ref : null;
    }

    private function safeAutorunJson(Request $request, string $endpoint, callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            $runId = (string) $request->query('run_id', '');
            $state = null;
            if ($runId !== '') {
                try {
                    $state = Cache::get($this->autorunCacheKey($runId));
                } catch (Throwable) {
                    $state = null;
                }
            }
            $safeMessage = $exception instanceof \PDOException ? 'database_error' : $exception->getMessage();
            $safeMessage = $safeMessage !== '' ? mb_substr($safeMessage, 0, 300) : class_basename($exception);

            $payload = [
                'ok' => false,
                'dry_run' => true,
                'ovoko_write' => false,
                'local_update' => false,
                'status' => 'failed',
                'error' => class_basename($exception),
                'warnings' => [],
                'endpoint' => $endpoint,
                'run_id' => $runId !== '' ? $runId : null,
                'stage' => is_array($state) ? ($state['stage'] ?? null) : null,
                'step' => $request->attributes->get('autorun_step'),
                'safe_error_message' => $safeMessage,
                'exception_class' => get_class($exception),
            ];

            if (config('app.debug') || $this->validToken($request)) {
                $payload['file'] = $this->redactSecretsFromPath($exception->getFile());
                $payload['line'] = $exception->getLine();
            }

            return response()->json($payload, 500);
        }
    }

    private function redactSecretsFromPath(string $path): string
    {
        return str_replace((string) base_path(), '', $path) ?: $path;
    }

    private function autorunCacheKey(string $runId): string { return 'ovoko_category_mapping_autorun_'.$runId; }
    private function putAutorun(array $state): void { Cache::put($this->autorunCacheKey($state['run_id']), $state, now()->addDay()); }
    private function autorunNextUrl(Request $request, string $runId): string { return url('/tools/run-ovoko-category-mapping-autorun').'?token='.urlencode((string)$request->query('token')).'&run_id='.urlencode($runId); }
    private function autorunPublicStateWithNextUrl(array $state, Request $request): array
    {
        $public = $this->autorunPublicState($state);
        if (in_array($public['status'] ?? null, ['started', 'running'], true) && (int) ($public['remaining_count'] ?? 0) > 0) {
            $public['next_url'] = $this->autorunNextUrl($request, (string) $public['run_id']);
        }

        return $public;
    }
    private function autorunPublicState(array $state): array
    {
        $final = $this->finalizeAutorunMappings($state, (int)($state['params']['sample_limit'] ?? 50));
        $processed=(int)($state['processed_count'] ?? 0); $total=(int)($state['processed_total'] ?? 0);
        $snapshot = $state['snapshot'] ?? [];
        $mapping = [
            'linked_products_processed' => $processed,
            'suggested_mapping_count' => $final['suggested_mapping_count'] ?? 0,
            'high_confidence_mapping_count' => $final['high_confidence_mapping_count'] ?? 0,
            'ambiguous_mapping_count' => $final['ambiguous_mapping_count'] ?? 0,
            'no_evidence_count' => $final['no_evidence_count'] ?? 0,
        ];
        return ['ok'=>true,'dry_run'=>true,'ovoko_write'=>false,'local_update'=>false,'run_id'=>$state['run_id'],'status'=>$state['status'],'stage'=>$state['stage'] ?? 'mapping','batch_size'=>(int)$state['params']['batch_size'],'time_limit_seconds'=>(int)($state['params']['tick_time_limit'] ?? 10),'time_spent_seconds'=>(float)($state['time_spent_seconds'] ?? 0),'processed_count'=>$processed,'processed_total'=>$total,'remaining_count'=>max(0,$total-$processed),'progress_percent'=>$total>0?round($processed*100/$total,2):100,'current_batch'=>$state['current_batch'] ?? ['stage'=>$state['stage'] ?? 'mapping','offset'=>(int)($state['current_offset'] ?? 0),'processed'=>0,'snapshot_pages_processed'=>0,'errors'=>[]],'snapshot'=>['page'=>(int)($snapshot['page'] ?? 0),'pages_processed'=>(int)($snapshot['pages_processed'] ?? 0),'total_seen'=>(int)($snapshot['total_seen'] ?? 0),'matched_linked_ids_count'=>(int)($snapshot['matched_linked_ids_count'] ?? 0),'failed_pages'=>array_values($snapshot['failed_pages'] ?? [])],'mapping'=>$mapping] + $final + ['sample_errors'=>$state['sample_errors'] ?? [],'next_url'=>null,'warnings'=>$state['warnings'] ?? []];
    }
    private function finalizeAutorunMappings(array $state, int $sampleLimit): array
    {
        $suggested=[]; $ambiguous=[]; $high=[]; $medium=[];
        foreach (($state['groups'] ?? []) as $group) { $observed=array_values($group['observed_ovoko_categories'] ?? []); if (count($observed)===1) { $cat=$observed[0]; $m=['local_category_id'=>$group['local_category_id'],'local_category_name'=>$group['local_category_name'],'local_category_path'=>$group['local_category_path'],'ovoko_category_id'=>$cat['ovoko_category_id'],'ovoko_category_name'=>$cat['ovoko_category_name'],'ovoko_category_path'=>$cat['ovoko_category_path'],'evidence_count'=>$cat['count'],'confidence'=>$cat['count'] >= 2 ? 'high' : 'medium','match_type'=>'linked_products_consensus']; $suggested[]=$m; if ($m['confidence'] === 'high') $high[] = $m; else $medium[] = $m; } elseif (count($observed)>1) { $ambiguous[]=['local_category_id'=>$group['local_category_id'],'local_category_path'=>$group['local_category_path'],'observed_ovoko_categories'=>$observed,'reason'=>'multiple_ovoko_categories_observed_for_one_local_category']; } }
        $existing = Schema::hasTable('marketplace_category_mappings') ? (int) DB::table('marketplace_category_mappings')->where('channel','ovoko')->distinct('local_category_id')->count('local_category_id') : 0;
        return ['suggested_mapping_count'=>count($suggested),'high_confidence_mapping_count'=>count($high),'medium_confidence_mapping_count'=>count($medium),'ambiguous_mapping_count'=>count($ambiguous),'no_evidence_count'=>count($state['no_evidence'] ?? []),'categories_with_existing_ovoko_mapping_count'=>$existing,'categories_missing_ovoko_mapping_count'=>max(0, $this->localCategoriesCount() - $existing),'suggested_mappings'=>$suggested,'high_confidence_mappings'=>$high,'medium_confidence_mappings'=>$medium,'ambiguous_mappings'=>$ambiguous,'no_evidence_categories'=>array_values($state['no_evidence'] ?? []),'sample_suggested_mappings'=>array_slice($suggested,0,$sampleLimit),'sample_high_confidence_mappings'=>array_slice($high,0,$sampleLimit),'sample_ambiguous_mappings'=>array_slice($ambiguous,0,$sampleLimit)];
    }
    private function localCategoriesCount(): int { $table = Schema::hasTable('part_categories') ? 'part_categories' : (Schema::hasTable('categories') ? 'categories' : null); return $table ? $this->safeCount($table) : 0; }

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

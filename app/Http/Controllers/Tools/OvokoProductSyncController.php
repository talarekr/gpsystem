<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\PartCategory;
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
use Illuminate\Support\Str;
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

        $ovoko = $this->marketplaceOvokoCategoriesWithParentPaths();

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


    private function marketplaceOvokoCategoriesWithParentPaths(): \Illuminate\Support\Collection
    {
        $rows = MarketplaceCategory::query()
            ->where('channel', 'ovoko')
            ->get(['external_category_id', 'parent_external_category_id', 'level', 'name', 'full_path'])
            ->map(fn (MarketplaceCategory $category): array => [
                'id' => (string) $category->external_category_id,
                'parent_id' => filled($category->parent_external_category_id) ? (string) $category->parent_external_category_id : null,
                'stored_level' => (int) $category->level,
                'name' => (string) $category->name,
                'stored_full_path' => (string) ($category->full_path ?: $category->name),
            ])
            ->filter(fn (array $row): bool => filled($row['id']) && filled($row['name']))
            ->values();

        $byId = $rows->keyBy('id');

        return $rows
            ->map(function (array $row) use ($byId): array {
                $fullPath = $this->ovokoMarketplaceCategoryParentPath($row, $byId);
                $level = max(1, count(explode(' > ', $fullPath)));

                return [
                    'id' => $row['id'],
                    'parent_id' => $row['parent_id'],
                    'level' => $level,
                    'name' => $row['name'],
                    'full_path' => $fullPath,
                    'stored_full_path' => $row['stored_full_path'],
                ];
            })
            ->filter(fn (array $row): bool => filled($row['full_path']))
            ->sortBy([['level', 'asc'], ['full_path', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function ovokoMarketplaceCategoryParentPath(array $category, $byId): string
    {
        $parts = [];
        $current = $category;
        $seen = [];
        $guard = 0;

        while ($current && $guard++ < 20) {
            $id = (string) ($current['id'] ?? '');
            if ($id !== '') {
                if (isset($seen[$id])) break;
                $seen[$id] = true;
            }

            $name = trim((string) ($current['name'] ?? ''));
            if ($name !== '') array_unshift($parts, $name);

            $parentId = $current['parent_id'] ?? null;
            $current = filled($parentId) && $byId->has((string) $parentId) ? $byId->get((string) $parentId) : null;
        }

        if ($parts === []) return (string) ($category['stored_full_path'] ?? $category['name'] ?? $category['id'] ?? '');

        return implode(' > ', $parts);
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


    public function dryRunDetectBadSlashSplitCategories(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(500, (int) $request->query('sample_limit', 100)));
        $only = (string) $request->query('only', 'all');
        if (! in_array($only, ['high_confidence', 'manual_review', 'safe_to_hide', 'all'], true)) $only = 'all';
        $includeProducts = $request->boolean('include_products', false);
        $warnings = ['read_only_no_local_or_marketplace_writes'];

        if (! Schema::hasTable('part_categories') || ! Schema::hasTable('marketplace_categories')) {
            return response()->json(['ok' => false, 'dry_run' => true, 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false, 'warnings' => ['missing_required_tables']], 422);
        }

        $ovokoRows = DB::table('marketplace_categories')->select($this->safeSelectColumns('marketplace_categories', ['id', 'external_category_id', 'name', 'full_path']))->where('channel', 'ovoko')->whereNotNull('full_path')->get()->map(fn ($r) => (array) $r)->all();
        $ovokoExact = [];
        $ovokoNorm = [];
        $ovokoSegmentNorm = [];
        foreach ($ovokoRows as $row) {
            $path = (string) ($row['full_path'] ?? '');
            if ($path === '') continue;
            $ovokoExact[$path][] = $row;
            $ovokoNorm[$this->normalizeCategoryPathForSlashDetection($path)][] = $row;
            foreach (explode(' > ', $path) as $segment) {
                $normSegment = $this->normalizeCategoryPathForSlashDetection($segment);
                if ($normSegment !== '') $ovokoSegmentNorm[$normSegment][] = $row;
            }
        }

        $localCategories = DB::table('part_categories')->select($this->safeSelectColumns('part_categories', ['id', 'name', 'category_path']))->get();
        $localPathByNorm = [];
        foreach ($localCategories as $cat) {
            $path = (string) (((array) $cat)['category_path'] ?? ((array) $cat)['name'] ?? '');
            if ($path !== '') $localPathByNorm[$this->normalizeCategoryPathForSlashDetection($path)][] = (array) $cat;
        }

        $productCounts = Schema::hasTable('parts') && Schema::hasColumn('parts', 'category_id')
            ? DB::table('parts')->select('category_id', DB::raw('count(*) as count'))->whereNotNull('category_id')->groupBy('category_id')->pluck('count', 'category_id')->map(fn ($v) => (int) $v)->all()
            : [];
        $mappingRows = Schema::hasTable('marketplace_category_mappings')
            ? DB::table('marketplace_category_mappings')->select($this->safeSelectColumns('marketplace_category_mappings', ['local_category_id', 'channel']))->get()->groupBy('local_category_id')
            : collect();

        $result = ['ok' => true, 'dry_run' => true, 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false, 'local_categories_count' => $localCategories->count(), 'ovoko_categories_count' => count($ovokoRows), 'exact_ovoko_match_count' => 0, 'normalized_ovoko_match_count' => 0, 'unmatched_local_count' => 0, 'suspected_bad_slash_split_count' => 0, 'high_confidence_fix_count' => 0, 'manual_review_count' => 0, 'with_products_count' => 0, 'without_products_count' => 0, 'safe_to_hide_empty_count' => 0, 'would_move_products_count' => 0, 'would_create_target_count' => 0, 'sample_high_confidence_fixes' => [], 'sample_manual_review' => [], 'sample_safe_to_hide_empty' => [], 'warnings' => $warnings];

        foreach ($localCategories as $catObj) {
            $cat = (array) $catObj;
            $path = (string) ($cat['category_path'] ?? $cat['name'] ?? '');
            if ($path === '') continue;
            if (isset($ovokoExact[$path])) { $result['exact_ovoko_match_count']++; continue; }
            $normPath = $this->normalizeCategoryPathForSlashDetection($path);
            if (isset($ovokoNorm[$normPath])) { $result['normalized_ovoko_match_count']++; continue; }
            $result['unmatched_local_count']++;

            $matches = [];
            foreach ($this->slashReconstructionCandidates($path) as $candidate) {
                $normCandidate = $this->normalizeCategoryPathForSlashDetection($candidate);
                foreach ($ovokoNorm[$normCandidate] ?? [] as $row) $matches[(string) ($row['external_category_id'] ?? $row['id'])] = $row;
                foreach ($ovokoSegmentNorm[$normCandidate] ?? [] as $row) $matches[(string) ($row['external_category_id'] ?? $row['id'])] = $row;
            }
            if ($matches === []) continue;

            $result['suspected_bad_slash_split_count']++;
            $productsCount = (int) ($productCounts[$cat['id']] ?? 0);
            $hasProducts = $productsCount > 0;
            $hasProducts ? $result['with_products_count']++ : $result['without_products_count']++;
            $channels = $mappingRows->get($cat['id'], collect())->pluck('channel')->map(fn ($v) => (string) $v)->all();
            $hasEbay = collect($channels)->contains(fn ($c) => str_starts_with($c, 'ebay'));
            $hasAllegro = collect($channels)->contains(fn ($c) => str_contains($c, 'allegro'));
            $hasOvoko = in_array('ovoko', $channels, true);
            $unique = count($matches) === 1;
            $target = $unique ? array_values($matches)[0] : null;
            $targetNorm = $target ? $this->normalizeCategoryPathForSlashDetection((string) ($target['full_path'] ?? '')) : null;
            $targetLocal = $targetNorm ? ($localPathByNorm[$targetNorm][0] ?? null) : null;
            $fixType = 'manual_review';
            $confidence = $unique ? 'high' : 'low';
            if ($unique && ! $hasEbay && ! $hasAllegro) {
                if (! $hasProducts && $channels === []) $fixType = 'hide_empty_bad_category';
                elseif ($hasProducts && $targetLocal) $fixType = 'move_products_to_existing_target';
                elseif ($hasProducts) $fixType = 'create_target_then_move';
            }
            if ($fixType === 'manual_review') { $result['manual_review_count']++; $confidence = 'low'; }
            else { $result['high_confidence_fix_count']++; }
            if ($fixType === 'hide_empty_bad_category') $result['safe_to_hide_empty_count']++;
            if ($fixType === 'move_products_to_existing_target') $result['would_move_products_count']++;
            if ($fixType === 'create_target_then_move') $result['would_create_target_count']++;

            $sample = ['local_category_id' => (int) $cat['id'], 'local_category_name' => $cat['name'] ?? null, 'local_category_path' => $path, 'local_products_count' => $productsCount, 'has_marketplace_mapping' => $channels !== [], 'has_ebay_mapping' => $hasEbay, 'has_allegro_mapping' => $hasAllegro, 'has_ovoko_mapping' => $hasOvoko, 'proposed_ovoko_category_id' => $target ? (string) ($target['external_category_id'] ?? $target['id']) : null, 'proposed_ovoko_path' => $target['full_path'] ?? null, 'proposed_target_local_category_id' => $targetLocal['id'] ?? null, 'target_exists_locally' => (bool) $targetLocal, 'fix_type' => $fixType, 'confidence' => $confidence, 'reason' => $unique ? 'local_segments_rejoined_with_slash_match_ovoko_path' : 'multiple_possible_ovoko_targets_manual_review'];
            if ($includeProducts && $hasProducts && Schema::hasTable('parts')) $sample['sample_product_ids'] = DB::table('parts')->where('category_id', $cat['id'])->limit(10)->pluck('id')->all();
            if ($fixType === 'manual_review' && ($only === 'all' || $only === 'manual_review')) $this->pushSample($result['sample_manual_review'], $sample, $sampleLimit);
            elseif ($fixType === 'hide_empty_bad_category' && ($only === 'all' || $only === 'safe_to_hide')) $this->pushSample($result['sample_safe_to_hide_empty'], $sample, $sampleLimit);
            elseif ($only === 'all' || $only === 'high_confidence') $this->pushSample($result['sample_high_confidence_fixes'], $sample, $sampleLimit);
        }

        return response()->json($result);
    }


    public function dryRunAuditShopCategoryTreeDisplay(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(500, (int) $request->query('sample_limit', 100)));
        $only = (string) $request->query('only', 'all');
        if (! in_array($only, ['bad_display', 'safe_to_hide', 'manual_review', 'all'], true)) $only = 'all';
        $includeChildren = $request->boolean('include_children', false);
        $includeProducts = $request->boolean('include_products', false);
        $warnings = ['read_only_audit_no_local_or_marketplace_writes'];

        $result = [
            'ok' => true,
            'dry_run' => true,
            'local_update' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'local_categories_count' => 0,
            'ovoko_categories_count' => 0,
            'ok_exact_ovoko_match_count' => 0,
            'suspected_bad_display_branch_count' => 0,
            'duplicate_branch_count' => 0,
            'bad_split_branch_count' => 0,
            'orphan_non_ovoko_branch_count' => 0,
            'safe_to_hide_empty_count' => 0,
            'needs_manual_review_count' => 0,
            'with_products_count' => 0,
            'with_marketplace_mapping_count' => 0,
            'sample_bad_display_branches' => [],
            'sample_safe_to_hide_empty' => [],
            'sample_manual_review' => [],
            'warnings' => $warnings,
        ];

        if (! Schema::hasTable('part_categories') || ! Schema::hasTable('marketplace_categories')) {
            $result['ok'] = false;
            $result['warnings'][] = 'missing_required_tables';
            return response()->json($result, 422);
        }

        $ovokoRows = DB::table('marketplace_categories')
            ->select($this->safeSelectColumns('marketplace_categories', ['id', 'external_category_id', 'name', 'full_path']))
            ->where('channel', 'ovoko')
            ->whereNotNull('full_path')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->filter(fn (array $row): bool => trim((string) ($row['full_path'] ?? '')) !== '')
            ->values()
            ->all();

        $ovokoNorm = [];
        foreach ($ovokoRows as $row) {
            $ovokoNorm[$this->normalizeCategoryPathForSlashDetection((string) $row['full_path'])][] = $row;
        }

        $localSelect = $this->safeSelectColumns('part_categories', ['id', 'parent_id', 'name', 'category_path']);
        $locals = DB::table('part_categories')->select($localSelect)->get()->map(fn ($row): array => (array) $row)->values();
        $result['local_categories_count'] = $locals->count();
        $result['ovoko_categories_count'] = count($ovokoRows);

        $localById = $locals->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''));
        $childrenByParent = $locals->groupBy(fn (array $row): string => (string) ($row['parent_id'] ?? ''));
        $localByNorm = [];
        foreach ($locals as $row) {
            $path = $this->localCategoryDisplayPath($row, $localById);
            $localByNorm[$this->normalizeCategoryPathForSlashDetection($path)][] = $row + ['_display_path' => $path];
        }

        $productCounts = Schema::hasTable('parts') && Schema::hasColumn('parts', 'category_id')
            ? DB::table('parts')->select('category_id', DB::raw('count(*) as count'))->whereNotNull('category_id')->groupBy('category_id')->pluck('count', 'category_id')->map(fn ($v) => (int) $v)->all()
            : [];
        $mappingRows = Schema::hasTable('marketplace_category_mappings')
            ? DB::table('marketplace_category_mappings')->select($this->safeSelectColumns('marketplace_category_mappings', ['local_category_id', 'channel']))->get()->groupBy('local_category_id')
            : collect();

        foreach ($locals as $cat) {
            $path = $this->localCategoryDisplayPath($cat, $localById);
            if ($path === '') continue;
            $normPath = $this->normalizeCategoryPathForSlashDetection($path);
            if (isset($ovokoNorm[$normPath])) {
                $result['ok_exact_ovoko_match_count']++;
                continue;
            }

            $children = $childrenByParent->get((string) ($cat['id'] ?? ''), collect());
            $childrenCount = $children->count();
            $productsCount = (int) ($productCounts[$cat['id']] ?? 0);
            $channels = $mappingRows->get($cat['id'], collect())->pluck('channel')->map(fn ($v) => (string) $v)->all();
            $hasMapping = $channels !== [];
            $hasEbay = collect($channels)->contains(fn (string $channel): bool => str_starts_with($channel, 'ebay'));
            if ($productsCount > 0) $result['with_products_count']++;
            if ($hasMapping) $result['with_marketplace_mapping_count']++;

            $matches = [];
            foreach ($this->shopTreeDisplayAuditCandidates($path) as $candidate) {
                foreach ($ovokoNorm[$this->normalizeCategoryPathForSlashDetection($candidate)] ?? [] as $row) {
                    $matches[(string) ($row['external_category_id'] ?? $row['id'])] = $row;
                }
            }

            $problemType = null;
            if ($matches !== []) $problemType = 'slash_split_branch';
            elseif ($this->looksLikeArtificialCategorySegment((string) ($cat['name'] ?? ''))) $problemType = 'bad_artificial_segment';
            elseif ($childrenCount > 0) $problemType = 'orphan_non_ovoko_branch';
            else continue;

            $target = count($matches) === 1 ? array_values($matches)[0] : null;
            $targetNorm = $target ? $this->normalizeCategoryPathForSlashDetection((string) ($target['full_path'] ?? '')) : null;
            $targetLocal = $targetNorm ? ($localByNorm[$targetNorm][0] ?? null) : null;
            $hasDuplicateLocal = $targetLocal !== null;
            if ($hasDuplicateLocal) $problemType = $problemType === 'slash_split_branch' ? 'slash_split_branch' : 'duplicate_branch';

            $suggested = 'manual_review';
            $confidence = $target && count($matches) === 1 ? 'high' : 'medium';
            if (count($matches) !== 1) $confidence = 'low';
            if ($productsCount === 0 && ! $hasMapping && $childrenCount === 0) $suggested = 'hide_empty_branch';
            elseif ($productsCount > 0 && $targetLocal) $suggested = 'move_products_then_hide';
            elseif ($productsCount > 0 && $target) $suggested = 'create_target_then_move';
            else $suggested = 'manual_review';

            $sample = [
                'local_category_id' => (int) $cat['id'],
                'local_category_name' => $cat['name'] ?? null,
                'local_category_path' => $path,
                'local_products_count' => $productsCount,
                'children_count' => $childrenCount,
                'has_marketplace_mapping' => $hasMapping,
                'has_ebay_mapping' => $hasEbay,
                'problem_type' => $problemType,
                'proposed_correct_ovoko_path' => $target['full_path'] ?? null,
                'proposed_correct_local_category_id' => $targetLocal['id'] ?? null,
                'target_exists_locally' => (bool) $targetLocal,
                'suggested_action' => $suggested,
                'confidence' => $confidence,
                'reason' => $target ? 'local tree segments can be rejoined with / to match Ovoko full_path' : 'local branch has children or artificial segment but no Ovoko full_path match',
            ];
            if ($includeChildren) $sample['children'] = $children->take(20)->map(fn (array $child): array => ['id' => $child['id'] ?? null, 'name' => $child['name'] ?? null, 'path' => $this->localCategoryDisplayPath($child, $localById)])->values()->all();
            if ($includeProducts && $productsCount > 0 && Schema::hasTable('parts')) $sample['sample_product_ids'] = DB::table('parts')->where('category_id', $cat['id'])->limit(10)->pluck('id')->all();

            $result['suspected_bad_display_branch_count']++;
            if ($hasDuplicateLocal) $result['duplicate_branch_count']++;
            if ($problemType === 'slash_split_branch') $result['bad_split_branch_count']++;
            if ($problemType === 'orphan_non_ovoko_branch') $result['orphan_non_ovoko_branch_count']++;
            if ($suggested === 'hide_empty_branch') $result['safe_to_hide_empty_count']++;
            if ($suggested === 'manual_review') $result['needs_manual_review_count']++;

            if ($only === 'all' || $only === 'bad_display') $this->pushSample($result['sample_bad_display_branches'], $sample, $sampleLimit);
            if ($suggested === 'hide_empty_branch' && ($only === 'all' || $only === 'safe_to_hide')) $this->pushSample($result['sample_safe_to_hide_empty'], $sample, $sampleLimit);
            if ($suggested === 'manual_review' && ($only === 'all' || $only === 'manual_review')) $this->pushSample($result['sample_manual_review'], $sample, $sampleLimit);
        }

        return response()->json($result);
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


    private function localCategoryDisplayPath(array $category, $localById): string
    {
        $path = trim((string) ($category['category_path'] ?? ''));
        if ($path !== '') return $path;
        $segments = [];
        $current = $category;
        $guard = 0;
        while ($current && $guard++ < 20) {
            array_unshift($segments, trim((string) ($current['name'] ?? '')));
            $parentId = $current['parent_id'] ?? null;
            $current = $parentId ? $localById->get((string) $parentId) : null;
        }
        return implode(' > ', array_values(array_filter($segments, fn (string $segment): bool => $segment !== '')));
    }

    private function shopTreeDisplayAuditCandidates(string $path): array
    {
        $candidates = $this->slashReconstructionCandidates($path);
        $segments = array_values(array_filter(array_map('trim', explode(' > ', $path)), fn (string $segment): bool => $segment !== ''));
        for ($start = 0; $start < count($segments) - 1; $start++) {
            $suffix = array_slice($segments, $start);
            if (count($suffix) >= 2) {
                $candidates[] = implode(' / ', $suffix);
                $candidates[] = implode('/', $suffix);
            }
        }
        return array_values(array_unique(array_filter($candidates, fn (string $candidate): bool => trim($candidate) !== '')));
    }

    private function looksLikeArtificialCategorySegment(string $name): bool
    {
        $normalized = $this->normalizeCategoryPathForSlashDetection($name);
        $segments = ['karoserii', 'wentylacji', 'chłodzenia silnika', 'komputery', 'moduły', 'moduly', 'sterowniki', 'a', 'c', 'fap', 'dpf'];
        return in_array($normalized, $segments, true);
    }

    private function normalizeCategoryPathForSlashDetection(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/\s*>\s*/u', ' > ', $value) ?? $value;
        $value = preg_replace('/\s*\/\s*/u', '/', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function slashReconstructionCandidates(string $path): array
    {
        $segments = array_values(array_filter(array_map('trim', explode(' > ', $path)), fn ($segment) => $segment !== ''));
        $count = count($segments);
        if ($count < 2 || $count > 12) return [];
        $out = [];
        $maxMask = 1 << ($count - 1);
        for ($mask = 1; $mask < $maxMask; $mask++) {
            $groups = [[$segments[0]]];
            for ($i = 1; $i < $count; $i++) {
                if ($mask & (1 << ($i - 1))) $groups[count($groups) - 1][] = $segments[$i];
                else $groups[] = [$segments[$i]];
            }
            $variants = [''];
            foreach ($groups as $group) {
                $joined = count($group) === 1 ? [$group[0]] : [implode(' / ', $group), implode('/', $group)];
                $next = [];
                foreach ($variants as $prefix) foreach (array_unique($joined) as $part) $next[] = $prefix === '' ? $part : $prefix.' > '.$part;
                $variants = $next;
            }
            foreach ($variants as $variant) $out[$variant] = true;
        }
        return array_keys($out);
    }




    public function dryRunAuditCategoryDisplayAgainstWooAndOvoko(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(500, (int) $request->query('sample_limit', 100)));
        $only = (string) $request->query('only', 'all');
        if (! in_array($only, ['all', 'split', 'front_visible', 'move_candidates', 'safe_to_hide', 'manual_review', 'canonical_ok'], true)) $only = 'all';
        $includeProducts = $request->boolean('include_products', true);
        $includeChildren = $request->boolean('include_children', true);
        $includeDescendants = $request->boolean('include_descendants', true);
        $csvPath = storage_path('app/imports/woo_category_tree.csv');

        $result = [
            'ok' => true, 'dry_run' => true, 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false,
            'csv_path' => 'storage/app/imports/woo_category_tree.csv', 'csv_exists' => is_file($csvPath),
            'woo_rows_count' => 0, 'laravel_categories_count' => 0, 'ovoko_categories_count' => 0, 'woo_matched_by_term_id_count' => 0,
            'canonical_ok_count' => 0, 'split_display_vs_ovoko_count' => 0, 'duplicate_has_existing_canonical_target_count' => 0, 'needs_canonical_target_create_count' => 0,
            'front_visible_problem_count' => 0, 'with_products_count' => 0, 'with_children_count' => 0, 'with_marketplace_mapping_count' => 0,
            'safe_to_hide_empty_count' => 0, 'move_products_candidate_count' => 0, 'manual_review_count' => 0,
            'sample_canonical_ok' => [], 'sample_split_display_vs_ovoko' => [], 'sample_front_visible_problems' => [], 'sample_move_products_candidates' => [], 'sample_safe_to_hide_empty' => [], 'sample_manual_review' => [],
            'warnings' => ['read_only_no_part_categories_products_marketplace_mappings_or_marketplace_writes'],
        ];
        if (! $result['csv_exists']) $result['warnings'][] = 'woo_category_tree_csv_not_found';

        $wooRows = $result['csv_exists'] ? $this->readWooCategoryTreeCsv($csvPath, $result['warnings']) : [];
        $result['woo_rows_count'] = count($wooRows);
        $wooByTerm = collect($wooRows)->filter(fn (array $row): bool => filled($row['term_id'] ?? null))->keyBy(fn (array $row): string => (string) $row['term_id']);

        $localRows = PartCategory::query()->with('marketplaceMappings')->get();
        $result['laravel_categories_count'] = $localRows->count();
        $localById = $localRows->keyBy(fn (PartCategory $cat): string => (string) $cat->id);
        $localInfos = $localRows->map(function (PartCategory $cat) use ($localById): array {
            $path = $this->localCategoryDisplayPath($cat->toArray(), $localById);
            return ['model' => $cat, 'path' => $path, 'normalized_path' => $this->normalizeCanonicalCategoryDisplayPath($path)];
        });
        $localByNormalizedPath = $localInfos->filter(fn (array $info): bool => $info['normalized_path'] !== '')->keyBy('normalized_path');

        $ovokoRows = Schema::hasTable('marketplace_categories')
            ? DB::table('marketplace_categories')->select($this->safeSelectColumns('marketplace_categories', ['id', 'external_category_id', 'name', 'full_path']))->where('channel', 'ovoko')->get()->map(fn ($row): array => (array) $row)->all()
            : [];
        $result['ovoko_categories_count'] = count($ovokoRows);
        $ovokoByNorm = collect($ovokoRows)->filter(fn (array $row): bool => filled($row['full_path'] ?? null))->groupBy(fn (array $row): string => $this->normalizeCanonicalCategoryDisplayPath((string) $row['full_path']));

        $partsCounts = $includeProducts && Schema::hasTable('parts') && Schema::hasColumn('parts', 'category_id')
            ? DB::table('parts')->select('category_id', DB::raw('count(*) as count'))->whereNotNull('category_id')->groupBy('category_id')->pluck('count', 'category_id')->all()
            : [];
        $childrenCounts = $includeChildren ? $localRows->groupBy('parent_id')->map->count()->all() : [];
        $mappingRows = Schema::hasTable('marketplace_category_mappings')
            ? DB::table('marketplace_category_mappings')->select($this->safeSelectColumns('marketplace_category_mappings', ['local_category_id', 'channel']))->get()->groupBy('local_category_id')
            : collect();

        foreach ($localInfos as $info) {
            /** @var PartCategory $cat */
            $cat = $info['model'];
            $path = $info['path'];
            $norm = $info['normalized_path'];
            $woo = filled($cat->external_id) ? $wooByTerm->get((string) $cat->external_id) : null;
            if ($woo) $result['woo_matched_by_term_id_count']++;
            $exactOvoko = $norm !== '' ? $ovokoByNorm->get($norm, collect()) : collect();
            if ($exactOvoko->isNotEmpty()) {
                $result['canonical_ok_count']++;
                if ($only === 'all' || $only === 'canonical_ok') $this->pushSample($result['sample_canonical_ok'], ['local_category_id' => $cat->id, 'local_category_name' => $cat->name, 'local_category_path' => $path, 'proposed_ovoko_category_id' => (string) ($exactOvoko->first()['external_category_id'] ?? $exactOvoko->first()['id'] ?? ''), 'proposed_ovoko_path' => $exactOvoko->first()['full_path'] ?? null, 'canonical_status' => 'canonical_ok'], $sampleLimit);
                continue;
            }

            $target = null;
            foreach ($this->shopTreeDisplayAuditCandidates($path) as $candidate) {
                $matches = $ovokoByNorm->get($this->normalizeCanonicalCategoryDisplayPath($candidate), collect());
                if ($matches->count() === 1) { $target = $matches->first(); break; }
            }
            if (! $target) continue;

            $productsCount = (int) ($partsCounts[$cat->id] ?? 0);
            $childrenCount = (int) ($childrenCounts[$cat->id] ?? 0);
            $descendantsProductsCount = $includeDescendants ? $this->descendantsProductsCountForPath($path, $localInfos, $partsCounts, (int) $cat->id) : 0;
            $channels = $mappingRows->get($cat->id, collect())->pluck('channel')->filter()->values()->all();
            $hasEbay = (bool) collect($channels)->first(fn ($c) => str_starts_with((string) $c, 'ebay'));
            $hasAllegro = in_array('allegro_main', $channels, true) || in_array('allegro', $channels, true);
            $hasOvoko = in_array('ovoko', $channels, true);
            $hasMapping = $channels !== [];
            $targetNorm = $this->normalizeCanonicalCategoryDisplayPath((string) ($target['full_path'] ?? ''));
            $targetLocal = $localByNormalizedPath->get($targetNorm);
            $targetExists = (bool) $targetLocal;
            $isActive = $this->categoryBooleanColumnValue($cat, ['active', 'is_active', 'status'], true);
            $isVisible = $this->categoryBooleanColumnValue($cat, ['is_visible', 'visible'], null);
            $showInMenu = $this->categoryBooleanColumnValue($cat, ['show_in_menu'], null);
            $hasActiveChildren = $this->hasActiveChildren($cat->id, $localRows);
            $frontReason = $this->frontVisibleReason($productsCount, $childrenCount, $isActive, $isVisible, $showInMenu);
            $frontVisible = $frontReason !== 'unknown';
            $suggested = $hasMapping ? 'manual_review' : ($productsCount > 0 ? 'move_products_to_canonical_target' : ($childrenCount > 0 ? 'manual_review' : ($targetExists ? 'hide_empty' : 'create_canonical_target_later')));
            if ($hasMapping) $suggested = 'manual_review';
            if ($productsCount > 0 && $suggested === 'hide_empty') $suggested = 'move_products_to_canonical_target';
            $confidence = $targetExists ? 'high' : 'medium';

            $sample = ['local_category_id' => $cat->id, 'local_category_name' => $cat->name, 'local_category_path' => $path, 'woo_match' => (bool) $woo, 'woo_term_id' => $woo['term_id'] ?? ($cat->external_id ?: null), 'woo_full_path' => $woo['full_path'] ?? null, 'local_products_count' => $productsCount, 'descendants_products_count' => $descendantsProductsCount, 'children_count' => $childrenCount, 'is_active' => $isActive, 'is_visible' => $isVisible, 'show_in_menu' => $showInMenu, 'has_active_children' => $hasActiveChildren, 'front_visible_reason' => $frontReason, 'has_marketplace_mapping' => $hasMapping, 'has_ebay_mapping' => $hasEbay, 'has_allegro_mapping' => $hasAllegro, 'has_ovoko_mapping' => $hasOvoko, 'proposed_ovoko_category_id' => (string) ($target['external_category_id'] ?? $target['id'] ?? ''), 'proposed_ovoko_path' => $target['full_path'] ?? null, 'target_exists_locally' => $targetExists, 'proposed_target_local_category_id' => $targetLocal['model']->id ?? null, 'proposed_target_local_path' => $targetLocal['path'] ?? null, 'canonical_status' => 'split_display_vs_ovoko', 'suggested_action' => $suggested, 'confidence' => $confidence, 'reason' => 'local_display_segments_rejoined_with_slash_match_ovoko_full_path'];

            $result['split_display_vs_ovoko_count']++;
            if ($targetExists) $result['duplicate_has_existing_canonical_target_count']++; else $result['needs_canonical_target_create_count']++;
            if ($frontVisible) $result['front_visible_problem_count']++;
            if ($productsCount > 0) $result['with_products_count']++;
            if ($childrenCount > 0) $result['with_children_count']++;
            if ($hasMapping) $result['with_marketplace_mapping_count']++;
            if ($suggested === 'hide_empty') $result['safe_to_hide_empty_count']++;
            if ($suggested === 'move_products_to_canonical_target') $result['move_products_candidate_count']++;
            if ($suggested === 'manual_review') $result['manual_review_count']++;

            if ($only === 'all' || $only === 'split') $this->pushSample($result['sample_split_display_vs_ovoko'], $sample, $sampleLimit);
            if (($only === 'all' || $only === 'front_visible') && $frontVisible) $this->pushSample($result['sample_front_visible_problems'], $sample, $sampleLimit);
            if (($only === 'all' || $only === 'move_candidates') && $suggested === 'move_products_to_canonical_target') $this->pushSample($result['sample_move_products_candidates'], $sample, $sampleLimit);
            if (($only === 'all' || $only === 'safe_to_hide') && $suggested === 'hide_empty') $this->pushSample($result['sample_safe_to_hide_empty'], $sample, $sampleLimit);
            if (($only === 'all' || $only === 'manual_review') && $suggested === 'manual_review') $this->pushSample($result['sample_manual_review'], $sample, $sampleLimit);
        }

        return response()->json($result);
    }

    public function dryRunCompareWooCategoryTreeWithLaravel(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(500, (int) $request->query('sample_limit', 100)));
        $only = (string) $request->query('only', 'all');
        if (! in_array($only, ['all', 'matched', 'woo_missing', 'laravel_extra', 'ebay_diff', 'artifacts'], true)) $only = 'all';
        $includeProducts = $request->boolean('include_products', false);
        $csvPath = storage_path('app/imports/woo_category_tree.csv');

        $result = [
            'ok' => true, 'dry_run' => true, 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false,
            'csv_path' => 'storage/app/imports/woo_category_tree.csv', 'csv_exists' => is_file($csvPath),
            'woo_rows_count' => 0, 'laravel_categories_count' => 0,
            'matched_by_term_id_count' => 0, 'matched_by_external_id_count' => 0, 'matched_by_slug_count' => 0, 'matched_by_full_path_count' => 0, 'matched_by_normalized_path_count' => 0,
            'woo_not_found_in_laravel_count' => 0, 'laravel_not_found_in_woo_count' => 0,
            'ebay_mapping_same_count' => 0, 'ebay_mapping_different_count' => 0, 'ebay_mapping_missing_in_laravel_count' => 0, 'ebay_mapping_missing_in_woo_count' => 0,
            'suspected_import_artifacts_count' => 0, 'suspected_bad_split_count' => 0,
            'sample_matched' => [], 'sample_woo_not_found_in_laravel' => [], 'sample_laravel_not_found_in_woo' => [], 'sample_ebay_mapping_different' => [], 'sample_import_artifacts' => [],
            'warnings' => ['read_only_no_part_categories_marketplace_mappings_products_or_marketplace_writes'],
        ];
        if (! $result['csv_exists']) { $result['ok'] = false; $result['warnings'][] = 'woo_category_tree_csv_not_found'; return response()->json($result, 404); }

        $wooRows = $this->readWooCategoryTreeCsv($csvPath, $result['warnings']);
        $result['woo_rows_count'] = count($wooRows);
        $localRows = PartCategory::query()->with('marketplaceMappings')->withCount('parts')->get();
        $result['laravel_categories_count'] = $localRows->count();
        $localById = $localRows->keyBy(fn ($cat) => (string) $cat->id);
        $localInfo = $localRows->map(function (PartCategory $cat) use ($localById) {
            $path = $this->localCategoryDisplayPath($cat->toArray(), $localById);
            $maps = $cat->marketplaceMappings->keyBy('channel');
            return ['model' => $cat, 'path' => $path, 'normalized_path' => $this->normalizedWooComparePath($path), 'mappings' => $maps];
        });

        $byExternal = $localRows->filter(fn ($c) => filled($c->external_id))->keyBy(fn ($c) => (string) $c->external_id);
        $byOldCategoryId = Schema::hasTable('marketplace_category_mappings') ? DB::table('marketplace_category_mappings')->whereNotNull('old_category_id')->where('old_category_id', '!=', '')->get()->keyBy(fn ($m) => (string) $m->old_category_id) : collect();
        $bySlug = $localRows->filter(fn ($c) => filled($c->slug))->keyBy(fn ($c) => (string) $c->slug);
        $byPath = $localInfo->filter(fn ($i) => filled($i['path']))->keyBy('path');
        $byNorm = $localInfo->filter(fn ($i) => filled($i['normalized_path']))->keyBy('normalized_path');
        $matchedLocal = []; $matchedWoo = [];

        foreach ($wooRows as $woo) {
            $match = null; $type = null; $term = (string) ($woo['term_id'] ?? '');
            if ($term !== '' && isset($byExternal[$term]) && (string) ($byExternal[$term]->source_system ?? '') === 'woo') { $match = $byExternal[$term]; $type = 'term_id'; $result['matched_by_term_id_count']++; }
            elseif ($term !== '' && isset($byExternal[$term])) { $match = $byExternal[$term]; $type = 'external_id'; $result['matched_by_external_id_count']++; }
            elseif ($term !== '' && $byOldCategoryId->has($term) && $localById->has((string) $byOldCategoryId->get($term)->local_category_id)) { $match = $localById->get((string) $byOldCategoryId->get($term)->local_category_id); $type = 'old_category_id'; $result['matched_by_external_id_count']++; }
            elseif (filled($woo['slug'] ?? null) && isset($bySlug[$woo['slug']])) { $match = $bySlug[$woo['slug']]; $type = 'slug'; $result['matched_by_slug_count']++; }
            elseif (filled($woo['full_path'] ?? null) && isset($byPath[$woo['full_path']])) { $match = $byPath[$woo['full_path']]['model']; $type = 'full_path'; $result['matched_by_full_path_count']++; }
            else { $norm = $this->normalizedWooComparePath($woo['full_path'] ?? null); if ($norm !== '' && isset($byNorm[$norm])) { $match = $byNorm[$norm]['model']; $type = 'normalized_path'; $result['matched_by_normalized_path_count']++; } }

            if (! $match) {
                $result['woo_not_found_in_laravel_count']++;
                if ($only === 'all' || $only === 'woo_missing') $this->pushSample($result['sample_woo_not_found_in_laravel'], $this->wooMissingSample($woo), $sampleLimit);
                continue;
            }
            $matchedLocal[(string) $match->id] = true; $matchedWoo[$term ?: (string) spl_object_id((object) $woo)] = true;
            $info = $localInfo->first(fn ($i) => (int) $i['model']->id === (int) $match->id);
            $ebay = $info['mappings']->get('ebay') ?? $info['mappings']->get('ebay_de');
            $ebayDe = $info['mappings']->get('ebay_de');
            if ($only === 'all' || $only === 'matched') $this->pushSample($result['sample_matched'], $this->matchedWooSample($woo, $match, $info['path'], $type, $ebay, $ebayDe), $sampleLimit);
            $this->compareWooEbayMapping($result, $woo, $match, $info['path'], $ebay, $ebayDe, $sampleLimit, $only);
        }

        foreach ($localInfo as $info) {
            $cat = $info['model']; $maps = $info['mappings']; $reason = $this->suspectedImportArtifactReason($cat->name, $info['path']);
            if ($reason !== null) { $result['suspected_import_artifacts_count']++; if (str_contains($reason, 'slash') || str_contains($reason, 'fragment')) $result['suspected_bad_split_count']++; if ($only === 'all' || $only === 'artifacts') $this->pushSample($result['sample_import_artifacts'], $this->localExtraSample($cat, $info['path'], $maps, $reason), $sampleLimit); }
            if (! isset($matchedLocal[(string) $cat->id])) { $result['laravel_not_found_in_woo_count']++; if ($only === 'all' || $only === 'laravel_extra') $this->pushSample($result['sample_laravel_not_found_in_woo'], $this->localExtraSample($cat, $info['path'], $maps, $reason), $sampleLimit); }
        }

        if (! $includeProducts) $result['warnings'][] = 'include_products_not_set_local_product_count_uses_eager_parts_count_only';
        return response()->json($result);
    }


    public function dryRunVerifyWooEbayMappingForCategorySplits(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(500, (int) $request->query('sample_limit', 100)));
        $only = (string) $request->query('only', 'all');
        if (! in_array($only, ['all', 'verified', 'conflicts', 'missing', 'copy_possible', 'manual_review'], true)) $only = 'all';
        $relativeCsvPath = 'storage/app/imports/RAFEL WEB DESIGNER (3).csv';
        $csvPath = storage_path('app/imports/RAFEL WEB DESIGNER (3).csv');
        $warnings = ['read_only_no_part_categories_marketplace_mappings_products_or_marketplace_writes'];

        $result = [
            'ok' => true, 'dry_run' => true, 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false,
            'woo_ebay_csv_path' => $relativeCsvPath, 'woo_ebay_csv_exists' => is_file($csvPath), 'woo_ebay_rows_count' => 0,
            'split_categories_count' => 0, 'split_categories_with_woo_term_id_count' => 0, 'woo_ebay_row_found_count' => 0, 'woo_ebay_row_missing_count' => 0,
            'source_ebay_de_mapping_same_count' => 0, 'source_ebay_de_mapping_different_count' => 0, 'source_ebay_de_mapping_missing_in_laravel_count' => 0, 'source_ebay_de_mapping_missing_in_csv_count' => 0,
            'source_ebay_fr_mapping_same_count' => 0, 'source_ebay_fr_mapping_different_count' => 0, 'source_ebay_fr_mapping_missing_in_laravel_count' => 0, 'source_ebay_fr_mapping_missing_in_csv_count' => 0,
            'target_mapping_empty_count' => 0, 'target_mapping_same_count' => 0, 'target_mapping_conflict_count' => 0,
            'can_copy_mapping_to_target_count' => 0, 'mapping_copy_not_needed_count' => 0, 'manual_review_count' => 0,
            'sample_verified' => [], 'sample_conflicts' => [], 'sample_missing_rows' => [], 'warnings' => &$warnings,
        ];

        if (! Schema::hasTable('part_categories') || ! Schema::hasTable('marketplace_categories') || ! Schema::hasTable('marketplace_category_mappings')) {
            $result['ok'] = false; $warnings[] = 'missing_required_tables'; return response()->json($result, 422);
        }
        if (! $result['woo_ebay_csv_exists']) { $result['ok'] = false; $warnings[] = 'woo_ebay_csv_not_found'; return response()->json($result, 404); }

        $wooRows = $this->readWooCategoryTreeCsv($csvPath, $warnings);
        $result['woo_ebay_rows_count'] = count($wooRows);
        $wooByTerm = collect($wooRows)->filter(fn (array $row): bool => filled($row['term_id'] ?? null))->keyBy(fn (array $row): string => (string) $row['term_id']);

        $localRows = PartCategory::query()->get();
        $localById = $localRows->keyBy(fn (PartCategory $cat): string => (string) $cat->id);
        $localInfos = $localRows->map(function (PartCategory $cat) use ($localById): array {
            $path = $this->localCategoryDisplayPath($cat->toArray(), $localById);
            return ['model' => $cat, 'path' => $path, 'normalized_path' => $this->normalizeCanonicalCategoryDisplayPath($path)];
        });
        $localByNormalizedPath = $localInfos->filter(fn (array $info): bool => $info['normalized_path'] !== '')->keyBy('normalized_path');
        $ovokoRows = DB::table('marketplace_categories')->select($this->safeSelectColumns('marketplace_categories', ['id', 'external_category_id', 'full_path']))->where('channel', 'ovoko')->get()->map(fn ($row): array => (array) $row)->all();
        $ovokoByNorm = collect($ovokoRows)->filter(fn (array $row): bool => filled($row['full_path'] ?? null))->groupBy(fn (array $row): string => $this->normalizeCanonicalCategoryDisplayPath((string) $row['full_path']));
        $mappings = DB::table('marketplace_category_mappings')->select($this->safeSelectColumns('marketplace_category_mappings', ['local_category_id', 'channel', 'external_category_id']))->whereIn('channel', ['ebay_de', 'ebay_fr'])->get()->groupBy('local_category_id');

        foreach ($localInfos as $info) {
            /** @var PartCategory $cat */
            $cat = $info['model']; $path = $info['path']; $norm = $info['normalized_path'];
            if ($path === '' || $ovokoByNorm->get($norm, collect())->isNotEmpty()) continue;
            $matches = collect();
            foreach ($this->shopTreeDisplayAuditCandidates($path) as $candidate) {
                $candidateMatches = $ovokoByNorm->get($this->normalizeCanonicalCategoryDisplayPath($candidate), collect());
                if ($candidateMatches->isNotEmpty()) $matches = $candidateMatches; if ($matches->count() === 1) break;
            }
            if ($matches->isEmpty()) continue;
            $target = $matches->count() === 1 ? $matches->first() : null;
            $targetNorm = $target ? $this->normalizeCanonicalCategoryDisplayPath((string) ($target['full_path'] ?? '')) : null;
            $targetLocal = $targetNorm ? $localByNormalizedPath->get($targetNorm) : null;
            $result['split_categories_count']++;
            $wooTermId = filled($cat->external_id) ? (string) $cat->external_id : '';
            if ($wooTermId !== '') $result['split_categories_with_woo_term_id_count']++;
            $woo = $wooTermId !== '' ? $wooByTerm->get($wooTermId) : null;
            if (! $woo) { $result['woo_ebay_row_missing_count']++; }
            else { $result['woo_ebay_row_found_count']++; }

            $sourceMaps = $mappings->get($cat->id, collect())->keyBy('channel');
            $targetMaps = $targetLocal ? $mappings->get($targetLocal['model']->id, collect())->keyBy('channel') : collect();
            $srcDe = $this->cleanCategoryMappingId($sourceMaps->get('ebay_de')->external_category_id ?? null);
            $srcFr = $this->cleanCategoryMappingId($sourceMaps->get('ebay_fr')->external_category_id ?? null);
            $wooDe = $this->cleanCategoryMappingId($woo['mapped_ebay_de_category_id'] ?? null);
            $wooFr = $this->cleanCategoryMappingId($woo['mapped_ebay_fr_category_id'] ?? null);
            $tgtDe = $this->cleanCategoryMappingId($targetMaps->get('ebay_de')->external_category_id ?? null);
            $tgtFr = $this->cleanCategoryMappingId($targetMaps->get('ebay_fr')->external_category_id ?? null);
            $deStatus = $this->mappingCompareStatus($srcDe, $wooDe); $frStatus = $this->mappingCompareStatus($srcFr, $wooFr);
            $result['source_ebay_de_mapping_'.$deStatus.'_count']++;
            $result['source_ebay_fr_mapping_'.$frStatus.'_count']++;

            $sourceMatchesWoo = in_array($deStatus, ['same', 'missing_in_csv'], true) && in_array($frStatus, ['same', 'missing_in_csv'], true) && ($deStatus === 'same' || $frStatus === 'same');
            $targetConflict = ($tgtDe !== '' && $wooDe !== '' && $tgtDe !== $wooDe) || ($tgtFr !== '' && $wooFr !== '' && $tgtFr !== $wooFr);
            $targetSame = ($tgtDe !== '' && $wooDe !== '' && $tgtDe === $wooDe) || ($tgtFr !== '' && $wooFr !== '' && $tgtFr === $wooFr);
            $targetEmpty = $tgtDe === '' && $tgtFr === '';
            $targetStatus = ! $targetLocal ? 'not_applicable' : ($targetConflict ? 'conflict' : ($targetEmpty ? 'empty' : ($targetSame ? 'same' : 'not_applicable')));
            if ($targetStatus === 'empty') $result['target_mapping_empty_count']++;
            if ($targetStatus === 'same') $result['target_mapping_same_count']++;
            if ($targetStatus === 'conflict') $result['target_mapping_conflict_count']++;
            $canCopy = $targetLocal && $targetEmpty && $sourceMatchesWoo;
            $notNeeded = $targetLocal && $targetSame && $sourceMatchesWoo && ! $targetConflict;
            $manual = ! $targetLocal || ! $woo || $targetConflict || ! $sourceMatchesWoo;
            if ($canCopy) $result['can_copy_mapping_to_target_count']++;
            if ($notNeeded) $result['mapping_copy_not_needed_count']++;
            if ($manual) $result['manual_review_count']++;
            $sample = ['local_category_id' => (int) $cat->id, 'local_category_path' => $path, 'woo_term_id' => $wooTermId ?: null, 'excluded_from_ebay' => $woo['excluded_from_ebay'] ?? null, 'source_laravel_ebay_de_category_id' => $srcDe ?: null, 'woo_mapped_ebay_de_category_id' => $wooDe ?: null, 'source_ebay_de_status' => $deStatus, 'source_laravel_ebay_fr_category_id' => $srcFr ?: null, 'woo_mapped_ebay_fr_category_id' => $wooFr ?: null, 'source_ebay_fr_status' => $frStatus, 'target_exists_locally' => (bool) $targetLocal, 'target_local_category_id' => $targetLocal['model']->id ?? null, 'target_local_path' => $targetLocal['path'] ?? null, 'target_ebay_de_category_id' => $tgtDe ?: null, 'target_ebay_fr_category_id' => $tgtFr ?: null, 'target_mapping_status' => $targetStatus, 'can_copy_mapping_to_target' => $canCopy, 'mapping_copy_not_needed' => $notNeeded, 'manual_review' => $manual, 'reason' => $canCopy ? 'woo_ebay_mapping_matches_source_and_target_empty' : ($notNeeded ? 'target_mapping_already_matches_verified_source' : ($targetConflict ? 'target_mapping_conflict' : (! $woo ? 'woo_ebay_row_missing' : 'manual_review_required')))] ;
            if ($woo && ! $manual && ($only === 'all' || $only === 'verified' || ($only === 'copy_possible' && $canCopy))) $this->pushSample($result['sample_verified'], $sample, $sampleLimit);
            if ($targetConflict && ($only === 'all' || $only === 'conflicts' || $only === 'manual_review')) $this->pushSample($result['sample_conflicts'], $sample, $sampleLimit);
            if (! $woo && ($only === 'all' || $only === 'missing' || $only === 'manual_review')) $this->pushSample($result['sample_missing_rows'], $sample, $sampleLimit);
        }
        unset($result['warnings']); $result['warnings'] = $warnings;
        return response()->json($result);
    }

    public function dryRunPlanFixCategoryDisplaySplits(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(500, (int) $request->query('sample_limit', 100)));
        $only = (string) $request->query('only', 'all');
        if (! in_array($only, ['all', 'auto_safe', 'manual_review', 'target_exists', 'target_create_needed'], true)) $only = 'all';
        $includeProducts = $request->boolean('include_products', false);
        $includeChildren = $request->boolean('include_children', false);
        $includeOperations = $request->boolean('include_operations', true);
        $csvPath = storage_path('app/imports/woo_category_tree.csv');
        $warnings = ['read_only_plan_no_part_categories_products_marketplace_mappings_or_marketplace_writes'];

        $result = [
            'ok' => true, 'dry_run' => true, 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false,
            'split_display_vs_ovoko_count' => 0, 'repair_groups_count' => 0,
            'target_exists_count' => 0, 'target_create_needed_count' => 0,
            'would_create_categories_count' => 0, 'would_move_products_count' => 0, 'would_move_products_total' => 0,
            'would_reparent_children_count' => 0, 'would_copy_mappings_count' => 0, 'would_hide_old_categories_count' => 0,
            'auto_safe_count' => 0, 'manual_review_count' => 0,
            'sample_repair_groups' => [], 'sample_auto_safe' => [], 'sample_manual_review' => [], 'warnings' => $warnings,
        ];

        if (! Schema::hasTable('part_categories') || ! Schema::hasTable('marketplace_categories')) {
            $result['ok'] = false; $result['warnings'][] = 'missing_required_tables';
            return response()->json($result, 422);
        }
        if (! is_file($csvPath)) $result['warnings'][] = 'woo_category_tree_csv_not_found';
        $wooByTerm = is_file($csvPath) ? collect($this->readWooCategoryTreeCsv($csvPath, $result['warnings']))->filter(fn (array $row): bool => filled($row['term_id'] ?? null))->keyBy(fn (array $row): string => (string) $row['term_id']) : collect();

        $localRows = PartCategory::query()->with('marketplaceMappings')->get();
        $localById = $localRows->keyBy(fn (PartCategory $cat): string => (string) $cat->id);
        $localInfos = $localRows->map(function (PartCategory $cat) use ($localById): array {
            $path = $this->localCategoryDisplayPath($cat->toArray(), $localById);
            return ['model' => $cat, 'path' => $path, 'normalized_path' => $this->normalizeCanonicalCategoryDisplayPath($path)];
        });
        $localByNormalizedPath = $localInfos->filter(fn (array $info): bool => $info['normalized_path'] !== '')->keyBy('normalized_path');
        $pathById = $localInfos->mapWithKeys(fn (array $info): array => [(int) $info['model']->id => $info['path']])->all();

        $ovokoRows = DB::table('marketplace_categories')->select($this->safeSelectColumns('marketplace_categories', ['id', 'external_category_id', 'name', 'full_path']))->where('channel', 'ovoko')->get()->map(fn ($row): array => (array) $row)->all();
        $ovokoByNorm = collect($ovokoRows)->filter(fn (array $row): bool => filled($row['full_path'] ?? null))->groupBy(fn (array $row): string => $this->normalizeCanonicalCategoryDisplayPath((string) $row['full_path']));
        $partsCounts = Schema::hasTable('parts') && Schema::hasColumn('parts', 'category_id') ? DB::table('parts')->select('category_id', DB::raw('count(*) as count'))->whereNotNull('category_id')->groupBy('category_id')->pluck('count', 'category_id')->map(fn ($v) => (int) $v)->all() : [];
        $childrenByParent = $localRows->groupBy(fn (PartCategory $cat): string => (string) ($cat->parent_id ?? ''));
        $mappingRows = Schema::hasTable('marketplace_category_mappings') ? DB::table('marketplace_category_mappings')->select($this->safeSelectColumns('marketplace_category_mappings', ['local_category_id', 'channel']))->get()->groupBy('local_category_id') : collect();

        $splits = [];
        foreach ($localInfos as $info) {
            /** @var PartCategory $cat */
            $cat = $info['model']; $path = $info['path']; $norm = $info['normalized_path'];
            if ($path === '' || $ovokoByNorm->get($norm, collect())->isNotEmpty()) continue;
            $matches = collect();
            foreach ($this->shopTreeDisplayAuditCandidates($path) as $candidate) {
                $candidateMatches = $ovokoByNorm->get($this->normalizeCanonicalCategoryDisplayPath($candidate), collect());
                if ($candidateMatches->isNotEmpty()) $matches = $candidateMatches; if ($matches->count() === 1) break;
            }
            if ($matches->isEmpty()) continue;
            $target = $matches->count() === 1 ? $matches->first() : null;
            $targetNorm = $target ? $this->normalizeCanonicalCategoryDisplayPath((string) ($target['full_path'] ?? '')) : null;
            $targetLocal = $targetNorm ? $localByNormalizedPath->get($targetNorm) : null;
            $channels = $mappingRows->get($cat->id, collect())->pluck('channel')->filter()->values()->map(fn ($v) => (string) $v)->all();
            $children = $childrenByParent->get((string) $cat->id, collect());
            $splits[(int) $cat->id] = [
                'cat' => $cat, 'path' => $path, 'matches_count' => $matches->count(), 'target' => $target, 'target_local' => $targetLocal,
                'products_count' => (int) ($partsCounts[$cat->id] ?? 0),
                'descendants_products_count' => $this->descendantsProductsCountForPath($path, $localInfos, $partsCounts, (int) $cat->id),
                'children_count' => $children->count(), 'channels' => $channels,
                'woo' => filled($cat->external_id) ? $wooByTerm->get((string) $cat->external_id) : null,
            ];
        }

        $result['split_display_vs_ovoko_count'] = count($splits);
        $childrenSplitIds = [];
        foreach ($splits as $id => $split) {
            $parentId = (int) ($split['cat']->parent_id ?? 0);
            if ($parentId && isset($splits[$parentId])) $childrenSplitIds[$id] = true;
        }
        foreach ($splits as $id => $split) {
            if (isset($childrenSplitIds[$id])) continue;
            $members = collect($splits)->filter(fn (array $candidate): bool => (int) $candidate['cat']->id === $id || str_starts_with((string) $candidate['path'], $split['path'].' > '))->values();
            $group = $this->categoryDisplaySplitRepairGroup($split, $members, $partsCounts, $pathById, $includeProducts, $includeChildren, $includeOperations);
            $result['repair_groups_count']++;
            $result[$group['target_exists_locally'] ? 'target_exists_count' : 'target_create_needed_count']++;
            foreach ($group['operations'] as $op) {
                if ($op['type'] === 'create_target_category') $result['would_create_categories_count']++;
                if ($op['type'] === 'move_products') { $result['would_move_products_count']++; $result['would_move_products_total'] += (int) $op['products_count']; }
                if ($op['type'] === 'reparent_children') $result['would_reparent_children_count']++;
                if ($op['type'] === 'copy_mapping') $result['would_copy_mappings_count']++;
                if ($op['type'] === 'hide_old_category') $result['would_hide_old_categories_count']++;
            }
            $bucket = $group['suggested_action'] === 'auto_fix_possible' ? 'auto_safe' : 'manual_review';
            $result[$bucket === 'auto_safe' ? 'auto_safe_count' : 'manual_review_count']++;
            if (($only === 'all') || ($only === $bucket) || ($only === 'target_exists' && $group['target_exists_locally']) || ($only === 'target_create_needed' && ! $group['target_exists_locally'])) $this->pushSample($result['sample_repair_groups'], $group, $sampleLimit);
            if ($bucket === 'auto_safe' && ($only === 'all' || $only === 'auto_safe')) $this->pushSample($result['sample_auto_safe'], $group, $sampleLimit);
            if ($bucket === 'manual_review' && ($only === 'all' || $only === 'manual_review')) $this->pushSample($result['sample_manual_review'], $group, $sampleLimit);
        }

        return response()->json($result);
    }


    public function categoryDisplaySplitsFixAutorun(Request $request)
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $token = (string) $request->query('token', self::TOKEN);
        $startUrl = url('/tools/start-category-display-splits-fix-autorun').'?token='.urlencode($token).'&confirm=1&batch_size=20&ignore_ebay_mapping=1&copy_mappings=0';
        $debugUrl = url('/tools/debug-category-display-splits-fix-autorun').'?token='.urlencode($token);
        return response(<<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>Category display splits fix autorun</title><style>body{font-family:system-ui;margin:24px;max-width:1200px}button{font-size:16px;padding:8px 14px;margin-right:8px}pre{background:#111;color:#eee;padding:16px;overflow:auto;max-height:420px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.card{border:1px solid #ddd;padding:12px;border-radius:8px}.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.error{color:#b00020;font-weight:700}</style></head><body>
<h1>Category display splits fix autorun</h1><p>Local Laravel-only repair. Marketplace mapping copy is disabled; no eBay/Ovoko/Allegro API writes.</p>
<div class="toolbar"><button onclick="startRun()">Start/Resume</button><button onclick="paused=true;state('paused')">Pause/Stop locally</button><button onclick="debugRun()">Debug</button></div><p id="state">idle</p><div id="cards" class="grid"></div><h2>Output</h2><pre id="out"></pre>
<script>
let runId=null, paused=false; const startUrl='$startUrl', debugUrl='$debugUrl';
function state(s){document.getElementById('state').textContent=s} function show(d){document.getElementById('out').textContent=JSON.stringify(d,null,2); runId=d.run_id||runId; const keys=['status','processed_groups_count','total_groups_count','created_categories_count','moved_products_count','reparented_children_count','hidden_old_categories_count','failed_count']; document.getElementById('cards').innerHTML=keys.map(k=>'<div class="card"><b>'+k+'</b><br>'+(d[k]??'')+'</div>').join('')}
async function get(u){const r=await fetch(u); const d=await r.json(); show(d); if(!r.ok) throw new Error(d.error_message||'request failed'); return d}
async function startRun(){paused=false; state('starting'); const d=await get(startUrl); if(d.next_url) tick(d.next_url)}
async function tick(u){if(paused)return; state('running'); const d=await get(u); if((d.status==='running'||d.status==='started')&&d.next_url&&!paused)setTimeout(()=>tick(d.next_url),400); else state(d.status||'done')}
async function debugRun(){state('debug'); await get(debugUrl)}
</script></body></html>
HTML);
    }

    public function debugCategoryDisplaySplitsFixAutorun(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $warnings = [];
        $groups = $this->buildCategoryDisplaySplitFixGroups($warnings);
        $visibility = $this->categoryDisplaySplitVisibilityColumns();
        $cacheKey = 'category_display_splits_fix_autorun_debug_'.Str::random(8);
        Cache::put($cacheKey, 'ok', now()->addMinute());
        return response()->json([
            'ok' => true, 'can_build_plan' => $groups !== [], 'split_categories_count' => collect($groups)->sum(fn ($g) => count($g['categories_in_group'] ?? [])),
            'repair_groups_count' => count($groups), 'estimated_create_categories_count' => collect($groups)->where('target_exists_locally', false)->count(),
            'estimated_move_products_count' => collect($groups)->sum(fn ($g) => collect($g['categories_in_group'])->sum('local_products_count')),
            'estimated_reparent_children_count' => collect($groups)->sum(fn ($g) => collect($g['categories_in_group'])->sum('children_count')),
            'estimated_hide_old_categories_count' => collect($groups)->sum(fn ($g) => count($g['categories_in_group'] ?? [])),
            'visibility_columns_detected' => $visibility, 'mapping_copy_disabled' => true, 'marketplace_api_write' => false,
            'sample_group' => $groups[0] ?? null, 'cache_write_test' => Cache::get($cacheKey) === 'ok', 'warnings' => $warnings,
        ]);
    }

    public function startCategoryDisplaySplitsFixAutorun(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'confirm=1 required'], 422);
        $warnings = [];
        $groups = $this->buildCategoryDisplaySplitFixGroups($warnings);
        $runId = (string) Str::uuid();
        $state = $this->categoryDisplaySplitInitialState($runId, $groups, max(1, min(100, (int) $request->query('batch_size', 20))), $request->boolean('ignore_ebay_mapping', true), $request->boolean('copy_mappings', false), $warnings);
        $this->putCategoryDisplaySplitRun($state);
        return response()->json($this->categoryDisplaySplitPublicStateWithNextUrl($state, $request));
    }

    public function runCategoryDisplaySplitsFixAutorun(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $state = Cache::get($this->categoryDisplaySplitRunCacheKey((string) $request->query('run_id', '')));
        if (! $state) return response()->json(['ok' => false, 'error_message' => 'run_id not found'], 404);
        if (($state['status'] ?? '') !== 'running') return response()->json($this->categoryDisplaySplitPublicState($state));
        $batchSize = (int) ($state['batch_size'] ?? 20);
        for ($i = 0; $i < $batchSize && (int) $state['processed_groups_count'] < (int) $state['total_groups_count']; $i++) {
            $idx = (int) $state['processed_groups_count'];
            try { $this->processCategoryDisplaySplitGroup($state, $state['groups'][$idx]); } catch (Throwable $e) { $state['failed_count']++; $this->pushSample($state['sample_errors'], ['group_index' => $idx, 'error' => $e->getMessage()], 20); }
            $state['processed_groups_count']++;
        }
        if ((int) $state['processed_groups_count'] >= (int) $state['total_groups_count']) $state['status'] = 'complete';
        $state['updated_at'] = now()->toISOString(); $this->putCategoryDisplaySplitRun($state);
        return response()->json($this->categoryDisplaySplitPublicStateWithNextUrl($state, $request));
    }

    public function statusCategoryDisplaySplitsFixAutorun(Request $request): JsonResponse { if (! $this->validToken($request)) return $this->invalidTokenResponse(); $state = Cache::get($this->categoryDisplaySplitRunCacheKey((string) $request->query('run_id', ''))); return $state ? response()->json($this->categoryDisplaySplitPublicState($state)) : response()->json(['ok'=>false,'error_message'=>'run_id not found'],404); }
    public function resultsCategoryDisplaySplitsFixAutorun(Request $request): JsonResponse { return $this->statusCategoryDisplaySplitsFixAutorun($request); }
    public function resetCategoryDisplaySplitsFixAutorun(Request $request): JsonResponse { if (! $this->validToken($request)) return $this->invalidTokenResponse(); if ((string) $request->query('confirm') !== '1') return response()->json(['ok'=>false,'error_message'=>'confirm=1 required'],422); Cache::forget($this->categoryDisplaySplitRunCacheKey((string) $request->query('run_id',''))); return response()->json(['ok'=>true,'reset'=>true]); }

    private function buildCategoryDisplaySplitFixGroups(array &$warnings): array
    {
        if (! Schema::hasTable('part_categories') || ! Schema::hasTable('marketplace_categories')) { $warnings[] = 'missing_required_tables'; return []; }
        $localRows = PartCategory::query()->with('marketplaceMappings')->get(); $localById = $localRows->keyBy(fn (PartCategory $cat): string => (string) $cat->id);
        $localInfos = $localRows->map(fn (PartCategory $cat): array => ['model'=>$cat,'path'=>$this->localCategoryDisplayPath($cat->toArray(), $localById),'normalized_path'=>$this->normalizeCanonicalCategoryDisplayPath($this->localCategoryDisplayPath($cat->toArray(), $localById))]);
        $localByNormalizedPath = $localInfos->filter(fn ($i) => $i['normalized_path'] !== '')->keyBy('normalized_path'); $pathById = $localInfos->mapWithKeys(fn ($i) => [(int) $i['model']->id => $i['path']])->all();
        $ovokoRows = DB::table('marketplace_categories')->select($this->safeSelectColumns('marketplace_categories', ['id','external_category_id','name','full_path']))->where('channel','ovoko')->get()->map(fn ($r)=>(array)$r)->all();
        $ovokoByNorm = collect($ovokoRows)->filter(fn ($r) => filled($r['full_path'] ?? null))->groupBy(fn ($r) => $this->normalizeCanonicalCategoryDisplayPath((string) $r['full_path']));
        $partsCounts = Schema::hasTable('parts') && Schema::hasColumn('parts','category_id') ? DB::table('parts')->select('category_id', DB::raw('count(*) as count'))->whereNotNull('category_id')->groupBy('category_id')->pluck('count','category_id')->map(fn ($v)=>(int)$v)->all() : [];
        $childrenByParent = $localRows->groupBy(fn (PartCategory $cat): string => (string) ($cat->parent_id ?? '')); $mappingRows = Schema::hasTable('marketplace_category_mappings') ? DB::table('marketplace_category_mappings')->select($this->safeSelectColumns('marketplace_category_mappings', ['local_category_id','channel']))->get()->groupBy('local_category_id') : collect();
        $splits = [];
        foreach ($localInfos as $info) { $cat=$info['model']; $path=$info['path']; if ($path==='' || $ovokoByNorm->get($info['normalized_path'], collect())->isNotEmpty()) continue; $matches=collect(); foreach ($this->shopTreeDisplayAuditCandidates($path) as $candidate) { $candidateMatches=$ovokoByNorm->get($this->normalizeCanonicalCategoryDisplayPath($candidate), collect()); if ($candidateMatches->isNotEmpty()) $matches=$candidateMatches; if ($matches->count()===1) break; } if ($matches->count() !== 1) continue; $target=$matches->first(); $targetNorm=$this->normalizeCanonicalCategoryDisplayPath((string)($target['full_path']??'')); $channels=$mappingRows->get($cat->id, collect())->pluck('channel')->filter()->values()->map(fn($v)=>(string)$v)->all(); $children=$childrenByParent->get((string)$cat->id, collect()); $splits[(int)$cat->id]=['cat'=>$cat,'path'=>$path,'matches_count'=>1,'target'=>$target,'target_local'=>$localByNormalizedPath->get($targetNorm),'products_count'=>(int)($partsCounts[$cat->id]??0),'descendants_products_count'=>$this->descendantsProductsCountForPath($path,$localInfos,$partsCounts,(int)$cat->id),'children_count'=>$children->count(),'channels'=>$channels,'woo'=>null]; }
        $childrenSplitIds=[]; foreach ($splits as $id=>$split) { $parentId=(int)($split['cat']->parent_id??0); if ($parentId && isset($splits[$parentId])) $childrenSplitIds[$id]=true; }
        $groups=[]; foreach ($splits as $id=>$split) { if (isset($childrenSplitIds[$id])) continue; $members=collect($splits)->filter(fn ($candidate) => (int)$candidate['cat']->id===$id || str_starts_with((string)$candidate['path'], $split['path'].' > '))->values(); $group=$this->categoryDisplaySplitRepairGroup($split,$members,$partsCounts,$pathById,false,false,true); $group['operations']=array_values(array_filter($group['operations'], fn ($op) => ($op['type'] ?? '') !== 'copy_mapping')); $groups[]=$group; }
        return $groups;
    }

    private function processCategoryDisplaySplitGroup(array &$state, array $group): void
    {
        foreach ($group['categories_in_group'] as $category) {
            $sourceId=(int)$category['local_category_id']; $targetPath=(string)$category['proposed_ovoko_path']; if ($sourceId <= 0 || $targetPath === '') { $state['skipped_groups_count']++; $this->pushSample($state['sample_skipped_groups'], $category, 20); continue; }
            $target = $this->ensureCanonicalCategoryForOvokoPath($targetPath, (string)($category['proposed_ovoko_category_id'] ?? ''), $state);
            $targetId = (int) $target->id; if ($targetId === $sourceId) continue;
            if (Schema::hasTable('parts') && Schema::hasColumn('parts','category_id')) { $moved=DB::table('parts')->where('category_id',$sourceId)->update(['category_id'=>$targetId]); $state['moved_products_count'] += $moved; if ($moved) $this->pushSample($state['sample_moved_products'], ['source_category_id'=>$sourceId,'target_category_id'=>$targetId,'count'=>$moved], 20); }
            $reparented = $this->reparentCategoryDisplaySplitChildrenIdempotently($sourceId, $targetId, $state);
            $state['reparented_children_count'] += $reparented; if ($reparented) $this->pushSample($state['sample_reparented_children'], ['source_category_id'=>$sourceId,'target_category_id'=>$targetId,'count'=>$reparented], 20);
            $remainingProducts = Schema::hasTable('parts') && Schema::hasColumn('parts','category_id') ? DB::table('parts')->where('category_id',$sourceId)->count() : 0; $remainingChildren = PartCategory::query()->where('parent_id',$sourceId)->count();
            if ($remainingProducts == 0 && $remainingChildren == 0) { if ($this->hideCategoryDisplaySplitOldCategory($sourceId)) { $state['hidden_old_categories_count']++; $this->pushSample($state['sample_hidden_old_categories'], ['source_category_id'=>$sourceId], 20); } else $state['warnings'][]='no_visibility_column_old_category_not_hidden:'.$sourceId; }
        }
    }

    private function ensureCanonicalCategoryForOvokoPath(string $fullPath, string $externalId, array &$state): PartCategory
    {
        $fullPath=trim($fullPath); $externalId=trim($externalId); $leaf=$this->canonicalCategoryLeafName($fullPath); $normPath=$this->normalizeCanonicalCategoryDisplayPath($fullPath); $normLeaf=$this->normalizeCanonicalCategoryDisplayPath($leaf);
        if ($externalId !== '' && Schema::hasColumn('part_categories','external_id')) { $existing=PartCategory::query()->where('external_id',$externalId)->first(); if ($existing) return $existing; }
        foreach (['category_path'=>$fullPath, 'name'=>$fullPath, 'name_leaf'=>$leaf] as $key=>$value) { if ($value === '') continue; $column=$key === 'name_leaf' ? 'name' : $key; if (Schema::hasColumn('part_categories',$column)) { $existing=PartCategory::query()->where($column,$value)->first(); if ($existing) return $existing; } }
        $all=PartCategory::query()->get(); $byId=$all->keyBy(fn ($c)=>(string)$c->id);
        foreach ($all as $cat) { $path=$this->localCategoryDisplayPath($cat->toArray(), $byId); if ($normPath !== '' && ($this->normalizeCanonicalCategoryDisplayPath((string)($cat->category_path ?? ''))===$normPath || $this->normalizeCanonicalCategoryDisplayPath($path)===$normPath)) return $cat; if ($normLeaf !== '' && $this->normalizeCanonicalCategoryDisplayPath((string)$cat->name)===$normLeaf) return $cat; }
        $data=['parent_id'=>null,'source_system'=>'ovoko','name'=>$leaf !== '' ? $leaf : $fullPath,'slug'=>Str::slug($leaf !== '' ? $leaf : $fullPath),'category_path'=>$fullPath,'legacy_payload'=>['source'=>'category_display_split_fix','ovoko_full_path'=>$fullPath]]; if (Schema::hasColumn('part_categories','external_id') && $externalId!=='') $data['external_id']=$externalId; foreach (['active','is_active','visible','is_visible','show_in_menu'] as $col) if (Schema::hasColumn('part_categories',$col)) $data[$col]=true;
        $wasCreated = false; try { $created=PartCategory::query()->create($data); $wasCreated = true; } catch (Throwable) { $created=$this->findCanonicalCategoryForOvokoPath($fullPath,$externalId) ?: throw new \RuntimeException('Could not create or find canonical category for Ovoko path: '.$fullPath); }
        if ($wasCreated) { $state['created_categories_count']++; $this->pushSample($state['sample_created_categories'], ['category_id'=>$created->id,'category_path'=>$fullPath], 20); } return $created;
    }

    private function findCanonicalCategoryForOvokoPath(string $fullPath, string $externalId): ?PartCategory
    { $norm=$this->normalizeCanonicalCategoryDisplayPath($fullPath); $leaf=$this->canonicalCategoryLeafName($fullPath); if ($externalId !== '' && Schema::hasColumn('part_categories','external_id')) { $cat=PartCategory::query()->where('external_id',$externalId)->first(); if ($cat) return $cat; } $all=PartCategory::query()->get(); $byId=$all->keyBy(fn ($c)=>(string)$c->id); return $all->first(fn ($cat) => $this->normalizeCanonicalCategoryDisplayPath((string)($cat->category_path ?? ''))===$norm || $this->normalizeCanonicalCategoryDisplayPath($this->localCategoryDisplayPath($cat->toArray(), $byId))===$norm || (trim((string)$cat->name)===$fullPath || trim((string)$cat->name)===$leaf)); }
    private function canonicalCategoryLeafName(string $fullPath): string { $parts=preg_split('/\s*>\s*/', trim($fullPath)); return trim((string) end($parts)); }
    private function reparentCategoryDisplaySplitChildrenIdempotently(int $sourceId, int $targetId, array &$state): int { $count=0; foreach (PartCategory::query()->where('parent_id',$sourceId)->where('id','!=',$targetId)->get() as $child) { try { $child->parent_id=$targetId; $child->save(); $count++; } catch (Throwable $e) { $state['warnings'][]='child_reparent_skipped_duplicate_or_constraint:'.$child->id; $this->pushSample($state['sample_skipped_groups'], ['source_category_id'=>$sourceId,'target_category_id'=>$targetId,'child_category_id'=>$child->id,'reason'=>'reparent_constraint_or_duplicate','error'=>$e->getMessage()], 20); } } return $count; }

    private function hideCategoryDisplaySplitOldCategory(int $categoryId): bool { $data=[]; foreach (['active','is_active','visible','is_visible','show_in_menu'] as $col) if (Schema::hasColumn('part_categories',$col)) $data[$col]=false; if (Schema::hasColumn('part_categories','status')) $data['status']='inactive'; if ($data===[]) return false; PartCategory::query()->whereKey($categoryId)->update($data); return true; }
    private function categoryDisplaySplitVisibilityColumns(): array { return array_values(array_filter(['active','is_active','visible','is_visible','show_in_menu','status'], fn ($c) => Schema::hasColumn('part_categories',$c))); }
    private function categoryDisplaySplitRunCacheKey(string $runId): string { return 'category_display_splits_fix_autorun_'.$runId; }
    private function putCategoryDisplaySplitRun(array $state): void { Cache::put($this->categoryDisplaySplitRunCacheKey($state['run_id']), $state, now()->addDay()); }
    private function categoryDisplaySplitInitialState(string $runId, array $groups, int $batchSize, bool $ignoreEbayMapping, bool $copyMappings, array $warnings): array { return ['ok'=>true,'local_update'=>true,'ovoko_write'=>false,'allegro_write'=>false,'ebay_write'=>false,'copy_mappings'=>$copyMappings,'ignore_ebay_mapping'=>$ignoreEbayMapping,'run_id'=>$runId,'status'=>'running','batch_size'=>$batchSize,'processed_groups_count'=>0,'total_groups_count'=>count($groups),'created_categories_count'=>0,'moved_products_count'=>0,'reparented_children_count'=>0,'hidden_old_categories_count'=>0,'skipped_groups_count'=>0,'failed_count'=>0,'sample_created_categories'=>[],'sample_moved_products'=>[],'sample_reparented_children'=>[],'sample_hidden_old_categories'=>[],'sample_skipped_groups'=>[],'sample_errors'=>[],'warnings'=>$warnings,'groups'=>$groups,'created_at'=>now()->toISOString(),'updated_at'=>now()->toISOString()]; }
    private function categoryDisplaySplitPublicStateWithNextUrl(array $state, Request $request): array { $public=$this->categoryDisplaySplitPublicState($state); if (($public['status'] ?? '') === 'running') $public['next_url']=url('/tools/run-category-display-splits-fix-autorun').'?token='.urlencode((string)$request->query('token')).'&run_id='.urlencode((string)$public['run_id']); return $public; }
    private function categoryDisplaySplitPublicState(array $state): array { unset($state['groups'], $state['batch_size']); $state['warnings']=array_values(array_unique($state['warnings'] ?? [])); return $state; }


    private function cleanCategoryMappingId(mixed $value): string
    {
        return trim((string) $value);
    }

    private function mappingCompareStatus(string $laravel, string $csv): string
    {
        if ($csv === '') return 'missing_in_csv';
        if ($laravel === '') return 'missing_in_laravel';
        return $laravel === $csv ? 'same' : 'different';
    }

    private function normalizeCanonicalCategoryDisplayPath(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/\s*>\s*/u', ' > ', $value) ?? $value;
        $value = preg_replace('/\s*\/\s*/u', '/', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function categoryDisplaySplitRepairGroup(array $root, $members, array $partsCounts, array $pathById, bool $includeProducts, bool $includeChildren, bool $includeOperations): array
    {
        $rootTarget = $root['target'] ?? null; $rootTargetLocal = $root['target_local'] ?? null;
        $hasAnyEbay = false; $hasAnyAllegro = false; $hasAmbiguous = false; $operations = []; $categories = [];
        foreach ($members as $member) {
            /** @var PartCategory $cat */
            $cat = $member['cat']; $channels = $member['channels'];
            $hasEbay = (bool) collect($channels)->first(fn (string $channel): bool => str_starts_with($channel, 'ebay'));
            $hasAllegro = in_array('allegro_main', $channels, true) || in_array('allegro', $channels, true);
            $hasAnyEbay = $hasAnyEbay || $hasEbay; $hasAnyAllegro = $hasAnyAllegro || $hasAllegro;
            $targetLocal = $member['target_local'] ?? null; $target = $member['target'] ?? null;
            $hasAmbiguous = $hasAmbiguous || (int) ($member['matches_count'] ?? 0) !== 1;
            $category = [
                'local_category_id' => (int) $cat->id, 'local_category_path' => $member['path'], 'local_products_count' => (int) $member['products_count'],
                'descendants_products_count' => (int) $member['descendants_products_count'], 'children_count' => (int) $member['children_count'],
                'has_marketplace_mapping' => $channels !== [], 'has_ebay_mapping' => $hasEbay, 'has_allegro_mapping' => $hasAllegro,
                'proposed_ovoko_category_id' => $target ? (string) ($target['external_category_id'] ?? $target['id'] ?? '') : null,
                'proposed_ovoko_path' => $target['full_path'] ?? null, 'target_exists_locally' => (bool) $targetLocal,
                'proposed_target_local_category_id' => $targetLocal['model']->id ?? null,
                'target_create_needed' => ! (bool) $targetLocal, 'needs_move_products' => (int) $member['products_count'] > 0,
                'needs_reparent_children' => (int) $member['children_count'] > 0, 'needs_copy_mapping' => $channels !== [], 'old_category_can_be_hidden_after_fix' => true,
            ];
            if ($includeProducts && (int) $member['products_count'] > 0 && Schema::hasTable('parts')) $category['sample_product_ids'] = DB::table('parts')->where('category_id', $cat->id)->limit(10)->pluck('id')->all();
            if ($includeChildren) $category['child_category_ids'] = collect($pathById)->filter(fn (string $path, int $id): bool => str_starts_with($path, $member['path'].' > ') && substr_count($path, ' > ') === substr_count($member['path'], ' > ') + 1)->keys()->values()->all();
            $categories[] = $category;

            if (! $includeOperations) continue;
            if (! $targetLocal) $operations[] = ['type' => 'create_target_category', 'source_category_id' => (int) $cat->id, 'target_category_id' => null, 'target_ovoko_category_id' => $category['proposed_ovoko_category_id'], 'products_count' => 0, 'mapping_count' => 0, 'safe' => ! $hasAmbiguous, 'reason' => 'canonical Ovoko category is matched but no local canonical category exists'];
            if ((int) $member['products_count'] > 0) $operations[] = ['type' => 'move_products', 'source_category_id' => (int) $cat->id, 'target_category_id' => $targetLocal['model']->id ?? null, 'target_ovoko_category_id' => $category['proposed_ovoko_category_id'], 'products_count' => (int) $member['products_count'], 'mapping_count' => 0, 'safe' => (bool) $target, 'reason' => 'move products from split display category to canonical category'];
            if ((int) $member['children_count'] > 0) $operations[] = ['type' => 'reparent_children', 'source_category_id' => (int) $cat->id, 'target_category_id' => $targetLocal['model']->id ?? null, 'target_ovoko_category_id' => $category['proposed_ovoko_category_id'], 'products_count' => 0, 'mapping_count' => 0, 'safe' => (bool) $target, 'reason' => 'keep branch together by reparenting direct children to canonical category'];
            if ($channels !== []) $operations[] = ['type' => 'copy_mapping', 'source_category_id' => (int) $cat->id, 'target_category_id' => $targetLocal['model']->id ?? null, 'target_ovoko_category_id' => $category['proposed_ovoko_category_id'], 'products_count' => 0, 'mapping_count' => count($channels), 'safe' => true, 'reason' => 'copy mappings only; do not delete source mappings in dry run plan'];
            $operations[] = ['type' => 'hide_old_category', 'source_category_id' => (int) $cat->id, 'target_category_id' => $targetLocal['model']->id ?? null, 'target_ovoko_category_id' => $category['proposed_ovoko_category_id'], 'products_count' => 0, 'mapping_count' => 0, 'safe' => (bool) $targetLocal, 'reason' => 'old split category can be hidden after products, children, and mappings are migrated'];
        }
        $targetExists = (bool) $rootTargetLocal; $targetCreateNeeded = ! $targetExists;
        $needsParentCreate = $targetCreateNeeded && str_contains((string) ($rootTarget['full_path'] ?? ''), ' > ');
        $confidence = $hasAmbiguous ? 'low' : ($targetExists ? 'high' : 'medium');
        $manual = $confidence !== 'high' || (($hasAnyEbay || $hasAnyAllegro) && $targetCreateNeeded) || ($members->contains(fn (array $m): bool => (int) $m['children_count'] > 0) && $targetCreateNeeded) || $needsParentCreate;
        return [
            'group_key' => 'split_display_fix:'.$root['cat']->id, 'root_local_category_id' => (int) $root['cat']->id, 'root_local_path' => $root['path'],
            'proposed_root_ovoko_category_id' => $rootTarget ? (string) ($rootTarget['external_category_id'] ?? $rootTarget['id'] ?? '') : null, 'proposed_root_ovoko_path' => $rootTarget['full_path'] ?? null,
            'target_exists_locally' => $targetExists, 'target_local_category_id' => $rootTargetLocal['model']->id ?? null, 'target_local_path' => $rootTargetLocal['path'] ?? null,
            'categories_in_group' => $categories, 'operations' => $operations,
            'risk_level' => $manual ? ($confidence === 'low' ? 'high' : 'medium') : 'low', 'confidence' => $confidence,
            'suggested_action' => $manual ? 'manual_review' : 'auto_fix_possible',
            'reason' => $manual ? 'requires manual review by safety rules before any future write runner' : 'high confidence grouped branch plan; still dry-run only',
        ];
    }

    private function descendantsProductsCountForPath(string $path, $localInfos, array $partsCounts, int $selfId): int
    {
        $prefix = $path.' > ';
        $total = 0;
        foreach ($localInfos as $info) {
            $id = (int) $info['model']->id;
            if ($id === $selfId) continue;
            if (str_starts_with((string) $info['path'], $prefix)) $total += (int) ($partsCounts[$id] ?? 0);
        }
        return $total;
    }

    private function categoryBooleanColumnValue(PartCategory $category, array $columns, ?bool $default): ?bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn('part_categories', $column)) continue;
            $value = $category->getAttribute($column);
            if ($value === null) return null;
            if ($column === 'status') return in_array(mb_strtolower((string) $value), ['1', 'active', 'published', 'visible', 'enabled'], true);
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
        }
        return $default;
    }

    private function hasActiveChildren(int $categoryId, $localRows): bool
    {
        foreach ($localRows as $child) {
            if ((int) ($child->parent_id ?? 0) === $categoryId && $this->categoryBooleanColumnValue($child, ['active', 'is_active', 'status'], true) === true) return true;
        }
        return false;
    }

    private function frontVisibleReason(int $productsCount, int $childrenCount, ?bool $isActive, ?bool $isVisible, ?bool $showInMenu): string
    {
        if ($productsCount > 0) return 'products';
        if ($childrenCount > 0) return 'children';
        if ($showInMenu === true) return 'show_in_menu';
        if ($isVisible === true) return 'active';
        if ($isActive === true) return 'active';
        return 'unknown';
    }

    private function readWooCategoryTreeCsv(string $path, array &$warnings): array
    {
        $handle = fopen($path, 'rb'); if (! $handle) { $warnings[] = 'csv_open_failed'; return []; }
        $header = fgetcsv($handle); if (! is_array($header)) { fclose($handle); $warnings[] = 'csv_header_missing'; return []; }
        $header = array_map(fn ($h) => trim((string) $h), $header); $rows = [];
        while (($data = fgetcsv($handle)) !== false) { $row = []; foreach ($header as $i => $key) $row[$key] = $data[$i] ?? null; $rows[] = $row; }
        fclose($handle); return $rows;
    }

    private function normalizedWooComparePath(?string $value): string
    { $value = mb_strtolower(trim((string) $value)); $value = str_replace(['/', '\\'], ' > ', $value); $value = preg_replace('/\s*>\s*/u', ' > ', $value) ?? $value; $value = preg_replace('/\s+/u', ' ', $value) ?? $value; return trim($value); }
    private function wooMissingSample(array $w): array { return ['woo_term_id'=>$w['term_id']??null,'woo_name'=>$w['name']??null,'woo_full_path'=>$w['full_path']??null,'woo_product_count'=>(int)($w['product_count']??0),'ebay_category_id'=>$w['ebay_category_id']??null,'ebay_category_id_de'=>$w['ebay_category_id_de']??null,'ebay_category_path_de'=>$w['ebay_category_path_de']??null]; }
    private function matchedWooSample(array $w, PartCategory $c, string $path, string $type, $ebay, $ebayDe): array { return ['woo_term_id'=>$w['term_id']??null,'woo_name'=>$w['name']??null,'woo_full_path'=>$w['full_path']??null,'laravel_category_id'=>$c->id,'laravel_name'=>$c->name,'laravel_path'=>$path,'match_type'=>$type,'woo_product_count'=>(int)($w['product_count']??0),'laravel_product_count'=>$c->parts_count ?? null,'woo_ebay_category_id'=>$w['ebay_category_id']??null,'woo_ebay_category_id_de'=>$w['ebay_category_id_de']??null,'laravel_ebay_category_id'=>$ebay?->external_category_id,'laravel_ebay_de_category_id'=>$ebayDe?->external_category_id]; }
    private function localExtraSample(PartCategory $c, string $path, $maps, ?string $reason): array { return ['local_category_id'=>$c->id,'local_category_name'=>$c->name,'local_category_path'=>$path,'product_count'=>$c->parts_count ?? null,'has_ebay_mapping'=>$maps->has('ebay') || $maps->has('ebay_de') || $maps->has('ebay_fr'),'has_allegro_mapping'=>$maps->has('allegro_main'),'has_ovoko_mapping'=>$maps->has('ovoko'),'suspected_reason'=>$reason]; }
    private function compareWooEbayMapping(array &$result, array $w, PartCategory $c, string $path, $ebay, $ebayDe, int $limit, string $only): void { $wooMain=trim((string)($w['ebay_category_id']??'')); $wooDe=trim((string)($w['ebay_category_id_de']??'')); $localMain=trim((string)($ebay?->external_category_id??'')); $localDe=trim((string)($ebayDe?->external_category_id??'')); if ($wooMain==='' && $wooDe==='') { if ($localMain!=='' || $localDe!=='') $result['ebay_mapping_missing_in_woo_count']++; return; } if ($localMain==='' && $localDe==='') { $result['ebay_mapping_missing_in_laravel_count']++; return; } if (($wooMain==='' || $wooMain===$localMain || $wooMain===$localDe) && ($wooDe==='' || $wooDe===$localDe || $wooDe===$localMain)) { $result['ebay_mapping_same_count']++; return; } $result['ebay_mapping_different_count']++; if ($only==='all'||$only==='ebay_diff') $this->pushSample($result['sample_ebay_mapping_different'], ['woo_term_id'=>$w['term_id']??null,'local_category_id'=>$c->id,'woo_full_path'=>$w['full_path']??null,'laravel_path'=>$path,'woo_ebay_category_id'=>$w['ebay_category_id']??null,'woo_ebay_category_id_de'=>$w['ebay_category_id_de']??null,'laravel_ebay_category_id'=>$ebay?->external_category_id,'laravel_ebay_de_category_id'=>$ebayDe?->external_category_id,'difference_reason'=>'woo_and_laravel_ebay_category_ids_do_not_match'], $limit); }
    private function suspectedImportArtifactReason(?string $name, ?string $path): ?string { $n=$this->normalizeCategoryPathForSlashDetection($name); $p=$this->normalizeCategoryPathForSlashDetection($path); if (preg_match('/(^| > )(c|fap|dpf|karoserii|moduły|moduly|sterowniki|sterownik|komputery)( > |$)/u', $p)) return 'suspicious_fragment_category_after_import_split'; if (str_contains($p, ' / ') || preg_match('/(^| > )[^>]+\/[^>]+( > |$)/u', $p)) return 'contains_slash_path_may_need_original_woo_comparison'; if (mb_strlen($n) <= 2 || in_array($n, ['c','fap','dpf','karoserii','moduły','moduly','sterowniki'], true)) return 'suspicious_short_or_fragment_name'; return null; }

    private function pushSample(array &$items, array $item, int $limit): void { if (count($items) < $limit) $items[] = $item; }
    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidTokenResponse(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
    private function emptyDryRunSummary(string $mode, int $page, int $limit): array { return ['ok' => true, 'dry_run' => true, 'local_update_only' => false, 'ovoko_write' => false, 'mode' => $mode, 'page' => $page, 'limit' => $limit, 'local_candidate_parts_count' => 0, 'already_has_ovoko_listing_count' => 0, 'missing_ovoko_listing_candidate_count' => 0, 'would_create_ovoko_count' => 0, 'blocked_count' => 0, 'warning_count' => 0, 'sample_would_create' => [], 'sample_already_listed' => [], 'sample_blocked' => [], 'sample_already_listed_blocked' => [], 'sample_missing_listing_blocked' => [], 'sample_create_missing_blocked' => [], 'sample_payloads' => [], 'required_fields' => self::REQUIRED_FIELDS, 'blockers' => [], 'top_blockers_already_listed' => [], 'top_blockers_missing_listing' => [], 'warnings' => ['dry_run_only_no_ovoko_or_other_marketplace_writes' => 1]]; }
}

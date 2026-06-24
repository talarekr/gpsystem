<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
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


    private function databaseTables(): array { try { return array_map(fn ($row) => reset($row), array_map('get_object_vars', DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))); } catch (\Throwable) { try { return array_map(fn ($row) => reset($row), array_map('get_object_vars', DB::select('SHOW TABLES'))); } catch (\Throwable) { return []; } } }
    private function looksLikeOvokoCategoryColumn(string $column): bool { return (bool) preg_match('/(ovoko|category|external|marketplace|source|import|slug|path|name|metadata|json|settings|raw|legacy)/i', $column); }
    private function safeCount(string $table): int { try { return (int) DB::table($table)->count(); } catch (\Throwable) { return 0; } }
    private function nonEmptyCount(string $table, string $column): int { try { return (int) DB::table($table)->whereNotNull($column)->where($column, '!=', '')->count(); } catch (\Throwable) { return 0; } }
    private function safeSelectColumns(string $table, array $wanted): array { $columns = Schema::getColumnListing($table); return array_values(array_intersect($wanted, $columns)) ?: ['id']; }
    private function columnSamples(string $table, string $column, int $limit): array { try { return DB::table($table)->select($this->safeSelectColumns($table, ['id', 'local_category_id', 'channel', 'external_category_id', 'external_category_name', 'external_category_path', 'name', 'category_path', 'slug', $column]))->whereNotNull($column)->where($column, '!=', '')->limit($limit)->get()->map(fn ($r) => (array) $r)->all(); } catch (\Throwable) { return []; } }
    private function tableSummary(?string $table, array $columns, int $limit): array { if (! $table || ! Schema::hasTable($table)) return ['table' => $table, 'exists' => false]; return ['table' => $table, 'exists' => true, 'record_count' => $this->safeCount($table), 'columns' => Schema::getColumnListing($table), 'samples' => DB::table($table)->select($this->safeSelectColumns($table, $columns))->limit($limit)->get()->map(fn ($r) => (array) $r)->all()]; }
    private function marketplaceMappingsSummary(int $limit): array { if (! Schema::hasTable('marketplace_category_mappings')) return ['exists' => false]; return ['exists' => true, 'count_per_channel' => DB::table('marketplace_category_mappings')->select('channel', DB::raw('count(*) as count'))->groupBy('channel')->orderBy('channel')->get()->map(fn ($r) => (array) $r)->all(), 'non_ovoko_external_category_id_count' => DB::table('marketplace_category_mappings')->where('channel', '!=', 'ovoko')->whereNotNull('external_category_id')->count(), 'samples' => DB::table('marketplace_category_mappings')->limit($limit)->get()->map(fn ($r) => (array) $r)->all()]; }
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

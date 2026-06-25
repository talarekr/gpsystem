<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SplFileObject;
use Throwable;

class SuggestAllegroCategoryMappingsFromLegacyCsvController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $sampleLimit = max(0, min(500, (int) $request->query('sample_limit', 100)));
        $recordLimit = max(1, min(10000, (int) $request->query('record_limit', 5000)));
        $minProducts = max(1, (int) $request->query('min_products', 1));
        $onlyMissingAllegro = $request->boolean('only_missing_allegro', true);
        $onlyPublic = $request->boolean('only_public', true);
        $leafOnly = $request->boolean('leaf_only', true);
        $categoryId = $request->query('category_id');
        $confidenceFilter = $request->query('confidence');

        $csvPath = $this->resolveCsvPath();
        $diagnostics = $this->emptyDiagnostics($csvPath);

        if (! $csvPath) {
            return response()->json($this->criticalPayload(
                $diagnostics,
                'Legacy Woo/Allegro CSV was not found in storage/app/imports.'
            ) + [
                'possible_match_fields_checked' => $this->possibleMatchFields(),
            ]);
        }

        try {
            $allegroNames = $this->allegroCategoryNames();
            $groups = [];
            $stats = ['total_legacy_rows' => 0, 'rows_with_allegro_category_id' => 0, 'matched_products_count' => 0, 'unmatched_products_count' => 0];

            foreach ($this->readRows($csvPath, $diagnostics) as $row) {
                if ($diagnostics['csv_rows_read'] >= $recordLimit) {
                    $this->addDiagnosticError($diagnostics, 'record_limit_reached', "Stopped after {$recordLimit} CSV rows.");
                    break;
                }

                $stats['total_legacy_rows']++;
                $diagnostics['csv_rows_read']++;

                $wooProductId = trim((string) ($row['woo_product_id'] ?? ''));
                $allegroOfferId = trim((string) ($row['allegro_offer_id'] ?? ''));

                if ($wooProductId === '') {
                    $diagnostics['missing_woo_product_id_count']++;
                }
                if ($allegroOfferId === '') {
                    $diagnostics['missing_allegro_offer_id_count']++;
                }

                $allegroCategoryId = $this->extractAllegroCategoryId((string) ($row['raw_allegro_meta_json'] ?? ''), $diagnostics);
                if ($allegroCategoryId !== null && $allegroCategoryId !== '') {
                    $stats['rows_with_allegro_category_id']++;
                } else {
                    $diagnostics['missing_allegro_category_id_count']++;
                }

                try {
                    $part = $wooProductId !== '' ? $this->findPartByWooProductId($wooProductId, $onlyPublic) : null;
                } catch (Throwable $e) {
                    $part = null;
                    $this->addDiagnosticError($diagnostics, 'product_lookup_failed', $e->getMessage(), ['woo_product_id' => $wooProductId]);
                }

                if (! $part || ! $part->category_id) {
                    $stats['unmatched_products_count']++;
                    $diagnostics['unmatched_products_count']++;
                    continue;
                }

                if ($categoryId !== null && (string) $part->category_id !== (string) $categoryId) {
                    continue;
                }
                if ($leafOnly && $this->categoryHasChildren((int) $part->category_id)) {
                    continue;
                }
                if ($onlyMissingAllegro && $this->hasAllegroMapping((int) $part->category_id)) {
                    continue;
                }

                $stats['matched_products_count']++;
                $diagnostics['matched_products_count']++;
                $key = (string) $part->category_id;
                $categoryName = trim((string) ($part->category_name ?? ''));
                $categoryPath = trim((string) ($part->category_path ?? ''));
                if ($categoryName === '' && $categoryPath === '') {
                    $this->addDiagnosticError($diagnostics, 'missing_category_name_or_path', 'Local category has neither path nor public name.', ['local_category_id' => (int) $part->category_id]);
                }

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'local_category_id' => (int) $part->category_id,
                        'local_category_name' => $categoryName !== '' ? $categoryName : null,
                        'category_path' => $categoryPath !== '' ? $categoryPath : ($categoryName !== '' ? $categoryName : null),
                        'matched_products_count' => 0,
                        'unmatched_products_count' => 0,
                        'total_legacy_rows' => 0,
                        'counts' => [],
                        'sample_products' => [],
                    ];
                }
                $groups[$key]['matched_products_count']++;
                $groups[$key]['total_legacy_rows']++;
                if ($allegroCategoryId !== null && $allegroCategoryId !== '') {
                    $groups[$key]['counts'][$allegroCategoryId] = ($groups[$key]['counts'][$allegroCategoryId] ?? 0) + 1;
                }
                if (count($groups[$key]['sample_products']) < $sampleLimit) {
                    $groups[$key]['sample_products'][] = ['local_product_id'=>(int)$part->id,'woo_product_id'=>$wooProductId,'sku'=>$part->sku,'product_name'=>$part->name,'allegro_offer_id'=>$allegroOfferId,'allegro_category_id'=>$allegroCategoryId];
                }
            }

            $suggestions = $this->buildSuggestions($groups, $allegroNames, $minProducts, $confidenceFilter);

            return response()->json($this->flags() + [
                'ok' => true,
                'dry_run' => $request->boolean('dry_run', true),
                'csv_path' => $csvPath,
                'parameters' => compact('sampleLimit','recordLimit','minProducts','onlyMissingAllegro','onlyPublic','leafOnly','categoryId','confidenceFilter'),
                'possible_match_fields_checked' => $this->possibleMatchFields(),
                'matched_products_count' => $stats['matched_products_count'],
                'unmatched_products_count' => $stats['unmatched_products_count'],
                'total_legacy_rows' => $stats['total_legacy_rows'],
                'rows_with_allegro_category_id' => $stats['rows_with_allegro_category_id'],
                'suggested_mapping_count' => count($suggestions),
                'suggested_mappings' => $suggestions,
                'diagnostics' => $diagnostics,
            ]);
        } catch (Throwable $e) {
            $this->addDiagnosticError($diagnostics, 'critical_error', $e->getMessage());

            return response()->json($this->criticalPayload($diagnostics, $e->getMessage()));
        }
    }

    private function buildSuggestions(array $groups, array $allegroNames, int $minProducts, ?string $confidenceFilter): array
    {
        $suggestions = [];
        foreach ($groups as $group) {
            arsort($group['counts']);
            $suggestedId = array_key_first($group['counts']);
            $suggestedCount = $suggestedId ? (int) $group['counts'][$suggestedId] : 0;
            if ($suggestedCount < $minProducts) continue;
            $share = $group['matched_products_count'] > 0 ? round($suggestedCount / $group['matched_products_count'], 4) : 0.0;
            $confidence = $share >= 0.9 ? 'high' : ($share >= 0.7 ? 'medium' : 'low');
            if ($confidenceFilter && $confidenceFilter !== $confidence) continue;
            $competing = [];
            foreach ($group['counts'] as $id => $count) {
                if ((string)$id === (string)$suggestedId) continue;
                $competing[] = ['allegro_category_id'=>(string)$id,'allegro_category_name'=>$allegroNames[(string)$id] ?? null,'count'=>(int)$count,'share'=>$group['matched_products_count'] > 0 ? round($count / $group['matched_products_count'], 4) : 0.0];
            }
            unset($group['counts']);
            $suggestions[] = $group + ['suggested_allegro_category_id'=>(string)$suggestedId,'suggested_allegro_category_name'=>$allegroNames[(string)$suggestedId] ?? null,'suggested_count'=>$suggestedCount,'suggested_share'=>$share,'confidence'=>$confidence,'competing_allegro_categories'=>$competing];
        }

        usort($suggestions, fn ($a, $b) => [$b['confidence'] === 'high', $b['suggested_share'], $b['matched_products_count']] <=> [$a['confidence'] === 'high', $a['suggested_share'], $a['matched_products_count']]);

        return $suggestions;
    }

    private function flags(): array { return ['read_only'=>true,'local_update'=>false,'ovoko_write'=>false,'allegro_write'=>false,'ebay_write'=>false,'products_changed'=>false,'offers_changed'=>false,'mappings_changed'=>false]; }
    private function possibleMatchFields(): array { return ['parts.source_system=woo + parts.external_id','parts.external_id','parts.legacy_payload.woo_product_id','parts.legacy_payload.id','parts.legacy_payload.product_id']; }
    private function resolveCsvPath(): ?string { $preferred = storage_path('app/imports/woo_allegro_legacy_mapping.csv'); if (is_file($preferred)) return $preferred; foreach (glob(storage_path('app/imports/*.csv')) ?: [] as $path) if (str_contains(strtolower(basename($path)), 'allegro')) return $path; return null; }

    private function emptyDiagnostics(?string $csvPath): array
    {
        return [
            'csv_path' => $csvPath,
            'csv_found' => $csvPath !== null,
            'csv_rows_read' => 0,
            'csv_rows_skipped' => 0,
            'invalid_json_count' => 0,
            'missing_woo_product_id_count' => 0,
            'missing_allegro_offer_id_count' => 0,
            'missing_allegro_category_id_count' => 0,
            'matched_products_count' => 0,
            'unmatched_products_count' => 0,
            'errors_sample' => [],
        ];
    }

    private function criticalPayload(array $diagnostics, string $error): array
    {
        return $this->flags() + [
            'ok' => false,
            'error' => $error,
            'diagnostics' => $diagnostics,
        ];
    }

    private function addDiagnosticError(array &$diagnostics, string $type, string $message, array $context = []): void
    {
        if (count($diagnostics['errors_sample']) >= 20) {
            return;
        }

        $diagnostics['errors_sample'][] = ['type' => $type, 'message' => $message, 'context' => $context];
    }

    private function readRows(string $path, array &$diagnostics): iterable
    {
        try {
            $file = new SplFileObject($path);
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
            $headers = null;
            foreach ($file as $rowNumber => $row) {
                try {
                    if (! is_array($row) || $row === [null]) {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = array_map(fn($v) => trim((string)$v), $row);
                        foreach (['woo_product_id', 'allegro_offer_id', 'raw_allegro_meta_json'] as $requiredHeader) {
                            if (! in_array($requiredHeader, $headers, true)) {
                                $this->addDiagnosticError($diagnostics, 'missing_csv_column', "Missing CSV column: {$requiredHeader}");
                            }
                        }
                        continue;
                    }
                    $normalizedRow = array_slice(array_pad($row, count($headers), null), 0, count($headers));
                    yield array_combine($headers, $normalizedRow) ?: [];
                } catch (Throwable $e) {
                    $diagnostics['csv_rows_skipped']++;
                    $this->addDiagnosticError($diagnostics, 'csv_row_parse_failed', $e->getMessage(), ['row_number' => $rowNumber + 1]);
                }
            }
        } catch (Throwable $e) {
            $this->addDiagnosticError($diagnostics, 'csv_parse_failed', $e->getMessage());
        }
    }

    private function extractAllegroCategoryId(string $json, array &$diagnostics): ?string
    {
        try {
            if (trim($json) === '') {
                return null;
            }

            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                $diagnostics['invalid_json_count']++;
                return null;
            }

            $categoryId = data_get($data, '_allegro_category_id', data_get($data, 'meta._allegro_category_id'));

            return $categoryId === null || $categoryId === '' ? null : (string) $categoryId;
        } catch (Throwable $e) {
            $diagnostics['invalid_json_count']++;
            $this->addDiagnosticError($diagnostics, 'invalid_raw_allegro_meta_json', $e->getMessage());

            return null;
        }
    }

    private function findPartByWooProductId(string $wooProductId, bool $onlyPublic): ?object
    {
        if (! Schema::hasTable('parts')) return null;
        $categoryNameSelect = Schema::hasColumn('part_categories', 'name') ? 'part_categories.name as category_name' : DB::raw('NULL as category_name');
        $categoryPathSelect = Schema::hasColumn('part_categories', 'category_path') ? 'part_categories.category_path' : DB::raw('NULL as category_path');
        $q = DB::table('parts')->leftJoin('part_categories', 'parts.category_id', '=', 'part_categories.id')->select('parts.id','parts.external_id','parts.sku','parts.name','parts.category_id','parts.legacy_payload',$categoryNameSelect,$categoryPathSelect);
        if ($onlyPublic && Schema::hasColumn('parts', 'is_visible_storefront')) $q->where('parts.is_visible_storefront', true);
        $matches = (clone $q)->where(fn($w) => $w->where(fn($x) => $x->where('parts.source_system','woo')->where('parts.external_id',$wooProductId))->orWhere('parts.external_id',$wooProductId))->limit(2)->get();
        if ($matches->count() === 1) return $matches->first();
        $payloadMatches = $q->where(function($w) use ($wooProductId) { foreach (['woo_product_id','id','product_id'] as $key) $w->orWhere("parts.legacy_payload->$key", $wooProductId); })->limit(2)->get();
        return $payloadMatches->count() === 1 ? $payloadMatches->first() : null;
    }

    private function categoryHasChildren(int $id): bool { return Schema::hasTable('part_categories') && Schema::hasColumn('part_categories', 'parent_id') && DB::table('part_categories')->where('parent_id', $id)->exists(); }
    private function hasAllegroMapping(int $id): bool { return Schema::hasTable('marketplace_category_mappings') && DB::table('marketplace_category_mappings')->where('local_category_id',$id)->whereIn('channel',['allegro','allegro_main'])->whereNotNull('external_category_id')->where('external_category_id','<>','')->exists(); }
    private function allegroCategoryNames(): array { if (! Schema::hasTable('marketplace_categories')) return []; return DB::table('marketplace_categories')->whereIn('channel',['allegro','allegro_main'])->pluck('name','external_category_id')->mapWithKeys(fn($v,$k)=>[(string)$k=>$v])->all(); }
}

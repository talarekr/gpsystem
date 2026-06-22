<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckEbayLegacyCategoryMappingsController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const MAX = 20;

    public function __invoke(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $categoryTable = $this->firstExisting(['part_categories', 'categories', 'product_categories']);
        $partsTable = Schema::hasTable('parts') ? 'parts' : null;
        $listingsTable = Schema::hasTable('marketplace_listings') ? 'marketplace_listings' : null;

        $schema = $this->schemaReport();
        $local = $this->localCategorySummary($categoryTable, $partsTable);
        $legacy = $this->categoryLegacySummary($categoryTable);
        $mappings = $this->detectedMappings($categoryTable, $partsTable, $listingsTable);
        $productListing = $this->productAndListingSummary($partsTable, $listingsTable);
        [$recommendations, $warnings, $blockers] = $this->recommendations($mappings, $local, $legacy, $productListing, $categoryTable);

        return response()->json([
            'ok' => true,
            'database_schema_detected' => $schema,
            'local_category_summary' => $local,
            'category_legacy_summary' => $legacy,
            'detected_category_mappings' => $mappings,
            'product_and_listing_legacy_summary' => $productListing,
            'recommendations' => $recommendations,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ]);
    }

    private function schemaReport(): array
    {
        $names = collect(DB::select('SHOW TABLES'))->map(fn ($row) => array_values((array) $row)[0] ?? null)->filter()->values()->all();
        $needles = ['categor', 'marketplace', 'mapping', 'listing', 'part'];
        $interesting = [];
        foreach ($names as $table) {
            if (! collect($needles)->contains(fn ($n) => str_contains(strtolower($table), $n))) {
                continue;
            }
            $cols = Schema::getColumnListing($table);
            $interesting[] = [
                'table' => $table,
                'columns_count' => count($cols),
                'matching_columns' => array_values(array_filter($cols, fn ($c) => preg_match('/ebay|marketplace|categor|external|term|slug|path|legacy|payload|meta/i', $c))),
                'row_count' => $this->safeCount($table),
            ];
        }

        return [
            'tables_checked_count' => count($names),
            'interesting_tables' => $interesting,
            'tables_present' => [
                'part_categories' => Schema::hasTable('part_categories'),
                'categories' => Schema::hasTable('categories'),
                'product_categories' => Schema::hasTable('product_categories'),
                'parts' => Schema::hasTable('parts'),
                'marketplace_listings' => Schema::hasTable('marketplace_listings'),
                'marketplace_category_mappings' => Schema::hasTable('marketplace_category_mappings'),
            ],
        ];
    }

    private function localCategorySummary(?string $table, ?string $partsTable): array
    {
        if (! $table) return ['available' => false];
        $cols = Schema::getColumnListing($table);
        $id = 'id'; $name = in_array('name', $cols, true) ? 'name' : $id;
        $used = ($partsTable && Schema::hasColumn($partsTable, 'category_id')) ? DB::table($partsTable)->whereNotNull('category_id')->distinct()->count('category_id') : 0;
        $leaf = in_array('parent_id', $cols, true) ? DB::table($table.' as c')->leftJoin($table.' as ch', 'ch.parent_id', '=', 'c.id')->whereNull('ch.id')->count('c.id') : null;
        $partCounts = ($partsTable && Schema::hasColumn($partsTable, 'category_id')) ? DB::table($partsTable)->select('category_id', DB::raw('count(*) as c'))->whereNotNull('category_id')->groupBy('category_id')->pluck('c', 'category_id') : collect();
        $samples = DB::table($table)->orderBy($id)->limit(self::MAX)->get()->map(fn ($r) => $this->categorySample((array) $r, $cols, $partCounts))->all();
        return [
            'category_table' => $table,
            'total_local_categories' => $this->safeCount($table),
            'final_leaf_categories_count' => $leaf,
            'categories_used_by_parts' => $used,
            'distinct_category_id_count_used_by_parts' => $used,
            'used_leaf_categories_count' => null,
            'sample_categories' => $samples,
        ];
    }

    private function categoryLegacySummary(?string $table): array
    {
        if (! $table) return ['available' => false];
        $cols = Schema::getColumnListing($table);
        $payloadCols = array_values(array_filter($cols, fn ($c) => preg_match('/legacy|payload|meta/i', $c)));
        $counts = ['categories_with_legacy_payload'=>0,'categories_where_legacy_payload_contains_ebay'=>0,'categories_where_legacy_payload_contains_ebay_de'=>0,'categories_where_legacy_payload_contains_ebay_fr'=>0,'categories_where_legacy_payload_contains_category_id'=>0,'categories_where_meta_contains_ebay'=>0];
        $samples = [];
        DB::table($table)->orderBy('id')->chunk(500, function ($rows) use (&$counts, &$samples, $payloadCols, $cols, $table) {
            foreach ($rows as $row) {
                $a = (array) $row; $text = strtolower(json_encode($a));
                $hasPayload = collect($payloadCols)->contains(fn($c) => ! empty($a[$c]));
                if ($hasPayload) $counts['categories_with_legacy_payload']++;
                if (str_contains($text,'ebay')) $counts['categories_where_legacy_payload_contains_ebay']++;
                if (str_contains($text,'ebay_de')) $counts['categories_where_legacy_payload_contains_ebay_de']++;
                if (str_contains($text,'ebay_fr')) $counts['categories_where_legacy_payload_contains_ebay_fr']++;
                if (str_contains($text,'category_id')) $counts['categories_where_legacy_payload_contains_category_id']++;
                if (str_contains($text,'meta') && str_contains($text,'ebay')) $counts['categories_where_meta_contains_ebay']++;
                if (count($samples) < self::MAX && str_contains($text, 'ebay')) $samples[] = $this->legacySample($a, $cols, $table);
            }
        });
        return $counts + ['sample_legacy_category_records' => $samples];
    }

    private function detectedMappings(?string $catTable, ?string $partsTable, ?string $listingsTable): array
    {
        $map = [];
        if ($catTable) {
            $cols = Schema::getColumnListing($catTable);
            DB::table($catTable)->orderBy('id')->chunk(500, function ($rows) use (&$map, $cols, $catTable) {
                foreach ($rows as $row) {
                    $a=(array)$row; $vals=$this->extractEbayValues($a); if (! $vals) continue;
                    $id=$a['id']; $map[$id] = $this->mappingRow($a, $cols, $vals, 'category legacy_payload/columns', 'high');
                }
            });
        }
        $summary = ['detected_ebay_de_category_mappings_count'=>0,'detected_ebay_fr_category_mappings_count'=>0,'detected_generic_ebay_category_mappings_count'=>0,'used_local_categories_with_ebay_mapping'=>0,'used_local_categories_missing_ebay_mapping'=>0,'ambiguous_mappings_count'=>0,'mappings_high_confidence_count'=>0,'mappings_medium_confidence_count'=>0,'mappings_low_confidence_count'=>0];
        foreach ($map as $m) {
            if ($m['ebay_de_category_id']) $summary['detected_ebay_de_category_mappings_count']++;
            if ($m['ebay_fr_category_id']) $summary['detected_ebay_fr_category_mappings_count']++;
            if ($m['generic_ebay_category_id']) $summary['detected_generic_ebay_category_mappings_count']++;
            $summary['mappings_'.$m['confidence'].'_confidence_count']++;
            if ($m['ambiguous']) $summary['ambiguous_mappings_count']++;
        }
        return ['summary' => $summary, 'sample_mappings' => array_slice(array_values($map), 0, self::MAX)];
    }

    private function productAndListingSummary(?string $partsTable, ?string $listingsTable): array
    {
        $out = ['parts_total'=>0,'parts_with_category_id'=>0,'parts_with_legacy_payload_containing_ebay'=>0,'sample_part_ids_with_ebay_related_legacy_data'=>[], 'marketplace_listings'=>['available'=>false]];
        if ($partsTable) {
            $out['parts_total']=$this->safeCount($partsTable); $out['parts_with_category_id']=Schema::hasColumn($partsTable,'category_id') ? DB::table($partsTable)->whereNotNull('category_id')->count() : 0;
            if (Schema::hasColumn($partsTable, 'legacy_payload')) {
                $rows=DB::table($partsTable)->select(['id','legacy_payload'])->where('legacy_payload','like','%ebay%')->limit(self::MAX)->get();
                $out['parts_with_legacy_payload_containing_ebay']=DB::table($partsTable)->where('legacy_payload','like','%ebay%')->count();
                $out['sample_part_ids_with_ebay_related_legacy_data']=$rows->map(fn($r)=>['part_id'=>$r->id,'safe_detected_keys'=>array_keys($this->extractEbayValues((array)$r))])->all();
            }
        }
        if ($listingsTable) {
            $out['marketplace_listings']=['available'=>true,'total_per_channel'=>DB::table($listingsTable)->select('marketplace',DB::raw('count(*) as c'))->groupBy('marketplace')->pluck('c','marketplace'),'count_ebay_de_listings'=>DB::table($listingsTable)->where('marketplace','like','%ebay%de%')->count(),'count_ebay_fr_listings'=>DB::table($listingsTable)->where('marketplace','like','%ebay%fr%')->count(),'count_ebay_listings_mapped_to_parts'=>DB::table($listingsTable)->where('marketplace','like','%ebay%')->whereNotNull('part_id')->count(),'count_ebay_listings_with_external_offer_id_or_listing_id'=>DB::table($listingsTable)->where('marketplace','like','%ebay%')->where(fn($q)=>$q->whereNotNull('external_offer_id')->orWhereNotNull('external_listing_id'))->count(),'has_external_category_id_column'=>Schema::hasColumn($listingsTable,'external_category_id'),'has_raw_payload_column'=>Schema::hasColumn($listingsTable,'raw_payload')];
        }
        return $out;
    }

    private function categorySample(array $a, array $cols, $partCounts): array { return ['id'=>$a['id']??null,'name'=>$a['name']??null,'slug'=>$a['slug']??null,'parent_id'=>$a['parent_id']??null,'path'=>$a['category_path']??($a['full_slug_path']??null),'parts_count'=>(int)($partCounts[$a['id']??0]??0),'old_woocommerce_term_id'=>$a['external_id']??($a['old_term_id']??null),'has_legacy_payload'=>!empty($a['legacy_payload'])]; }
    private function legacySample(array $a, array $cols, string $table): array { $vals=$this->extractEbayValues($a); return ['local_category_id'=>$a['id']??null,'local_category_name_path'=>$a['category_path']??($a['name']??null),'detected_old_term_id'=>$a['external_id']??null,'safe_detected_keys'=>array_keys($vals),'possible_ebay_category_values'=>$vals,'source_column'=>'legacy_payload/columns','source_table'=>$table]; }
    private function mappingRow(array $a, array $cols, array $vals, string $source, string $confidence): array { $all=array_unique(array_values($vals)); return ['local_category_id'=>$a['id']??null,'local_category_name_path'=>$a['category_path']??($a['name']??null),'old_category_id'=>$a['external_id']??null,'ebay_de_category_id'=>$vals['ebay_de_category_id']??null,'ebay_de_category_name_path'=>$vals['ebay_de_category_name']??null,'ebay_fr_category_id'=>$vals['ebay_fr_category_id']??null,'ebay_fr_category_name_path'=>$vals['ebay_fr_category_name']??null,'generic_ebay_category_id'=>$vals['ebay_category_id']??($vals['category_id']??null),'products_count'=>null,'source'=>$source,'confidence'=>$confidence,'ambiguous'=>count($all)>1]; }
    private function extractEbayValues(array $row): array { $flat=$this->flatten($row); $out=[]; foreach($flat as $k=>$v){ if(!is_scalar($v)||$v===''||preg_match('/token|secret|key|password/i',$k)) continue; if(preg_match('/ebay.*(category.*id|catid)|ebay_de|de.*ebay|ebay_fr|fr.*ebay|external_category_id/i',$k)){ $out[$k]=(string)$v; }} return array_slice($out,0,20,true); }
    private function flatten(array $a, string $p=''): array { $r=[]; foreach($a as $k=>$v){ $key=$p===''?(string)$k:$p.'.'.$k; if(is_string($v)&&str_starts_with(trim($v),'{')) $v=json_decode($v,true)?:$v; if(is_array($v)) $r+=$this->flatten($v,$key); else $r[$key]=$v; } return $r; }
    private function firstExisting(array $tables): ?string { foreach($tables as $t) if(Schema::hasTable($t)) return $t; return null; }
    private function safeCount(string $table): int { return (int) DB::table($table)->count(); }
    private function recommendations(array $m, array $l, array $legacy, array $pl, ?string $cat): array
    {
        $s = $m['summary'];
        $has = ($s['detected_ebay_de_category_mappings_count'] + $s['detected_ebay_fr_category_mappings_count'] + $s['detected_generic_ebay_category_mappings_count']) > 0;
        $recommendations = [
            ['status' => $has ? 'yes' : 'no', 'message' => $has ? 'W Laravel wykryto możliwe stare mapowania lokalnych kategorii do eBay.' : 'Nie wykryto pewnych mapowań lokalnych kategorii do eBay w tabelach kategorii.'],
            ['message' => $has ? 'Mapowanie jest widoczne przy kategoriach; produkty/listingi pozostają źródłem pomocniczym.' : 'Jeżeli mappingi istnieją tylko w produktach/listingach, trzeba je potraktować jako średnią lub niską pewność.'],
            ['message' => $has ? 'Późniejsze automatyczne utworzenie marketplace_category_mappings powinno być możliwe po walidacji próbek.' : 'Automatyczne utworzenie marketplace_category_mappings wymaga odzyskania term meta/custom table ze starego Woo/WordPress/dumpa.'],
            ['message' => ($s['detected_ebay_de_category_mappings_count'] && $s['detected_ebay_fr_category_mappings_count']) ? 'eBay DE i FR wyglądają na rozróżnialne.' : 'Nie ma pełnej pewności rozróżnienia eBay DE i FR.'],
            ['message' => 'Kategorie używane przez produkty bez wykrytego mappingu są raportowane w licznikach used_local_categories_missing_ebay_mapping, jeśli dane relacji są dostępne.'],
        ];
        $warnings = ['Diagnostyka jest read-only i nie wykonuje requestów do eBay API ani zapisów do bazy.', 'Próbki pokazują wyłącznie bezpieczne klucze i wartości kategorii, bez pełnych payloadów.'];
        $blockers = $cat ? [] : ['Nie znaleziono tabeli lokalnych kategorii (part_categories/categories/product_categories).'];

        return [$recommendations, $warnings, $blockers];
    }

}

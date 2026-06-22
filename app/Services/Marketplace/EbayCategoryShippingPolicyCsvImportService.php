<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SplFileObject;

class EbayCategoryShippingPolicyCsvImportService
{
    private const CSV_PATH = 'app/imports/RAFEL WEB DESIGNER (1).csv';
    private const CHANNELS = ['ebay_de', 'ebay_fr'];
    private const BLOCK_REASON_DE = 'Zablokowane w legacy CSV: DO WYWALENIA — nie wystawiać na eBay.de';
    private const BLOCK_REASON_FR = 'Zablokowane w legacy CSV: DO WYWALENIA — nie wystawiać na eBay.fr';
    private const MAX_SAMPLES = 20;

    private const POLICIES = [
        'ebay_de' => [
            '30' => ['fulfillment_policy_id' => '259264150013', 'shipping_group' => 'de_30_eur'],
            '50' => ['fulfillment_policy_id' => '259677066013', 'shipping_group' => 'de_50_eur'],
            '130' => ['fulfillment_policy_id' => '259636579013', 'shipping_group' => 'de_130_eur'],
        ],
        'ebay_fr' => [
            '30' => ['fulfillment_policy_id' => '260547694013', 'shipping_group' => 'fr_55_eur'],
            '50' => ['fulfillment_policy_id' => '260547464013', 'shipping_group' => 'fr_70_eur'],
            '130' => ['fulfillment_policy_id' => '260547754013', 'shipping_group' => 'fr_130_eur'],
        ],
    ];

    public function dryRun(): array { return $this->import(false); }
    public function live(): array { return $this->import(true); }

    public function coverage(): array
    {
        $warnings = ['Read-only coverage check; no eBay API calls and no database writes are performed.'];
        $blockers = $this->schemaBlockers(false);
        if ($blockers !== []) return ['ok' => false, 'blockers' => $blockers, 'warnings' => $warnings];

        return ['ok' => true, 'channels' => [
            'ebay_de' => $this->coverageFor('ebay_de'),
            'ebay_fr' => $this->coverageFor('ebay_fr'),
        ], 'blockers' => [], 'warnings' => $warnings];
    }

    private function import(bool $live): array
    {
        $csvPath = storage_path(self::CSV_PATH);
        $warnings = ['Importer uses only the configured local CSV and the current Laravel database; no WordPress/WooCommerce/eBay API/listing/price/stock/order operations are performed.'];
        $blockers = $this->schemaBlockers($live);
        if (! is_file($csvPath)) $blockers[] = 'CSV file is missing at storage_path(\''.self::CSV_PATH.'\').';

        $base = $this->emptyImportResponse($csvPath, is_file($csvPath), $warnings, $blockers);
        if ($blockers !== []) return $base;

        $rows = $this->readCsv($csvPath);
        $indexes = $this->categoryIndexes();
        $actions = [];
        $matched = $unmatched = $conflicts = $skipped = 0;
        $samples = ['matches'=>[], 'unmatched'=>[], 'conflicts'=>[], 'skipped'=>[], 'blocked_de'=>[], 'blocked_fr'=>[]];
        $csvCounts = ['30'=>0, '50'=>0, '130'=>0, 'DO WYWALENIA'=>0];
        $withGroup = 0;

        foreach ($rows as $row) {
            $group = $this->normalizeGroup($row['shipping_group'] ?? null);
            if ($group === null) continue;
            $withGroup++;
            if (isset($csvCounts[$group])) $csvCounts[$group]++;

            $match = $this->matchCategory($row, $indexes);
            if ($match['status'] === 'conflict') { $conflicts++; $this->sample($samples['conflicts'], $match + ['csv' => $this->csvSample($row)]); continue; }
            if ($match['status'] === 'unmatched') { $unmatched++; $this->sample($samples['unmatched'], $this->csvSample($row)); continue; }
            $matched++;
            $this->sample($samples['matches'], $match + ['csv' => $this->csvSample($row)]);

            if ($group === 'DO WYWALENIA') {
                $actions[] = ['local_category_id'=>$match['id'], 'channel'=>'ebay_de', 'fulfillment_policy_id'=>null, 'shipping_group'=>null, 'is_blocked'=>true, 'block_reason'=>self::BLOCK_REASON_DE, 'match'=>$match, 'group'=>$group];
                $actions[] = ['local_category_id'=>$match['id'], 'channel'=>'ebay_fr', 'fulfillment_policy_id'=>null, 'shipping_group'=>null, 'is_blocked'=>true, 'block_reason'=>self::BLOCK_REASON_FR, 'match'=>$match, 'group'=>$group];
                $this->sample($samples['blocked_de'], $match + ['csv' => $this->csvSample($row)]);
                $this->sample($samples['blocked_fr'], $match + ['csv' => $this->csvSample($row)]);
                continue;
            }
            if (! isset(self::POLICIES['ebay_de'][$group])) { $skipped++; $this->sample($samples['skipped'], $this->csvSample($row) + ['reason'=>'unsupported_shipping_group']); continue; }
            foreach (self::CHANNELS as $channel) {
                $policy = self::POLICIES[$channel][$group];
                $actions[] = ['local_category_id'=>$match['id'], 'channel'=>$channel, 'fulfillment_policy_id'=>$policy['fulfillment_policy_id'], 'shipping_group'=>$policy['shipping_group'], 'is_blocked'=>false, 'block_reason'=>null, 'match'=>$match, 'group'=>$group];
            }
        }

        if ($live && $conflicts === 0) {
            foreach ($actions as $a) {
                MarketplaceCategoryMapping::query()->updateOrCreate(['local_category_id'=>$a['local_category_id'], 'channel'=>$a['channel']], [
                    'fulfillment_policy_id'=>$a['fulfillment_policy_id'], 'shipping_group'=>$a['shipping_group'], 'is_blocked'=>$a['is_blocked'], 'block_reason'=>$a['block_reason'],
                ]);
            }
        } elseif ($live && $conflicts > 0) $blockers[] = 'Conflicts detected; live import did not write anything.';

        $perGroup = []; $perPolicy = [];
        foreach ($actions as $a) { if ($a['shipping_group']) $perGroup[$a['shipping_group']] = ($perGroup[$a['shipping_group']] ?? 0) + 1; if ($a['fulfillment_policy_id']) $perPolicy[$a['fulfillment_policy_id']] = ($perPolicy[$a['fulfillment_policy_id']] ?? 0) + 1; }

        return array_merge($base, ['ok'=>$blockers===[], 'mode'=>$live?'live':'dry_run', 'csv_rows_total'=>count($rows), 'csv_rows_with_shipping_group'=>$withGroup, 'csv_rows_30_count'=>$csvCounts['30'], 'csv_rows_50_count'=>$csvCounts['50'], 'csv_rows_130_count'=>$csvCounts['130'], 'csv_rows_do_wywalenia_count'=>$csvCounts['DO WYWALENIA'], 'matched_categories_count'=>$matched, 'unmatched_categories_count'=>$unmatched, 'conflict_count'=>$conflicts, 'would_update_ebay_de_shipping_count'=>$this->actionCount($actions,'ebay_de',false), 'would_update_ebay_fr_shipping_count'=>$this->actionCount($actions,'ebay_fr',false), 'would_block_ebay_de_count'=>$this->actionCount($actions,'ebay_de',true), 'would_block_ebay_fr_count'=>$this->actionCount($actions,'ebay_fr',true), 'would_clear_fulfillment_for_blocked_count'=>$this->actionCount($actions,'ebay_de',true) + $this->actionCount($actions,'ebay_fr',true), 'would_skip_count'=>$skipped, 'count_per_shipping_group'=>$perGroup, 'count_per_fulfillment_policy_id'=>$perPolicy, 'sample_matches'=>$samples['matches'], 'sample_unmatched'=>$samples['unmatched'], 'sample_conflicts'=>$samples['conflicts'], 'sample_skipped'=>$samples['skipped'], 'sample_blocked_ebay_de'=>$samples['blocked_de'], 'sample_blocked_ebay_fr'=>$samples['blocked_fr'], 'blockers'=>$blockers]);
    }

    private function readCsv(string $path): array
    {
        $file = new SplFileObject($path); $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY); $headers = null; $rows = [];
        foreach ($file as $line) { if ($line === [null] || $line === false) continue; if ($headers === null) { $headers = array_map(fn($h)=>trim((string)$h), $line); continue; } $r=[]; foreach ($headers as $i=>$h) $r[$h !== '' ? $h : 'Unnamed: '.$i] = $line[$i] ?? null; $r['shipping_group'] = $r['Unnamed: 4'] ?? $r[''] ?? null; $rows[]=$r; }
        return $rows;
    }

    private function categoryIndexes(): array
    {
        $cols = ['id','name','category_path','full_slug_path','legacy_payload']; $oldCol = Schema::hasColumn('part_categories','old_category_id') ? 'old_category_id' : (Schema::hasColumn('part_categories','external_id') ? 'external_id' : null); if ($oldCol) $cols[]=$oldCol;
        $byOld=[]; $byPath=[]; $byName=[]; $all=[];
        DB::table('part_categories')->select($cols)->orderBy('id')->chunk(500, function($rows) use (&$byOld,&$byPath,&$byName,&$all,$oldCol){ foreach($rows as $r){ $all[]=$r; if($oldCol && filled($r->{$oldCol})) $byOld[(string)$r->{$oldCol}][]=$r; foreach([$r->category_path ?? null, $r->full_slug_path ?? null] as $p) if(filled($p)) $byPath[$this->norm($p)][]=$r; if(filled($r->name)) $byName[$this->norm($r->name)][]=$r; }});
        return compact('byOld','byPath','byName','all');
    }

    private function matchCategory(array $row, array $idx): array
    {
        $term = trim((string)($row['term_id'] ?? '')); if($term !== '' && isset($idx['byOld'][$term])) return $this->unique($idx['byOld'][$term], 'old_category_id', 'high');
        $payload = array_values(array_filter($idx['all'], fn($r) => $this->payloadHasTerm($this->decode($r->legacy_payload ?? null), $term))); if($payload !== []) return $this->unique($payload, 'legacy_payload.term_id', 'high');
        $pathKey = $this->norm($row['full_path'] ?? ''); if($pathKey !== '' && isset($idx['byPath'][$pathKey])) return $this->unique($idx['byPath'][$pathKey], 'full_path', 'high');
        $nameKey = $this->norm($row['name'] ?? ''); if($nameKey !== '' && isset($idx['byName'][$nameKey])) return $this->unique($idx['byName'][$nameKey], 'name', 'medium');
        return ['status'=>'unmatched'];
    }
    private function unique(array $rows, string $source, string $confidence): array { $ids=array_values(array_unique(array_map(fn($r)=>(int)$r->id,$rows))); if(count($ids)!==1) return ['status'=>'conflict','source'=>$source,'confidence'=>$confidence,'candidate_ids'=>$ids]; $r=$rows[0]; return ['status'=>'matched','id'=>(int)$r->id,'local_category_name'=>$r->name ?? null,'local_category_path'=>($r->category_path ?? null) ?: ($r->full_slug_path ?? null),'source'=>$source,'confidence'=>$confidence]; }
    private function payloadHasTerm(array $v, string $term): bool { if($term==='') return false; foreach($v as $k=>$val){ if((string)$k==='term_id' && (string)$val===$term) return true; if(is_array($val)&&$this->payloadHasTerm($val,$term)) return true; } return false; }
    private function coverageFor(string $channel): array { $used=$this->usedCategoryIds(); $q=DB::table('marketplace_category_mappings')->where('channel',$channel); $missing=(clone $q)->whereIn('local_category_id',$used)->whereNull('fulfillment_policy_id')->where(function($qq){$qq->whereNull('is_blocked')->orWhere('is_blocked',false);})->pluck('local_category_id')->map(fn($v)=>(int)$v)->all(); return ['total_mappings'=>(clone $q)->count(),'used_mapped_categories_total'=>(clone $q)->whereIn('local_category_id',$used)->distinct()->count('local_category_id'),'used_mapped_categories_with_fulfillment_policy_id'=>(clone $q)->whereIn('local_category_id',$used)->whereNotNull('fulfillment_policy_id')->distinct()->count('local_category_id'),'used_mapped_categories_missing_fulfillment_policy_id'=>(clone $q)->whereIn('local_category_id',$used)->whereNull('fulfillment_policy_id')->distinct()->count('local_category_id'),'blocked_count'=>(clone $q)->where('is_blocked',true)->count(),'used_mapped_categories_blocked_count'=>(clone $q)->whereIn('local_category_id',$used)->where('is_blocked',true)->distinct()->count('local_category_id'),'missing_fulfillment_policy_excluding_blocked_count'=>count($missing),'count_per_shipping_group'=>(clone $q)->select('shipping_group',DB::raw('count(*) as c'))->whereNotNull('shipping_group')->groupBy('shipping_group')->pluck('c','shipping_group'),'count_per_fulfillment_policy_id'=>(clone $q)->select('fulfillment_policy_id',DB::raw('count(*) as c'))->whereNotNull('fulfillment_policy_id')->groupBy('fulfillment_policy_id')->pluck('c','fulfillment_policy_id'),'sample_missing_categories'=>$this->categorySamples($missing),'sample_blocked_categories'=>$this->categorySamples((clone $q)->where('is_blocked',true)->limit(self::MAX_SAMPLES)->pluck('local_category_id')->map(fn($v)=>(int)$v)->all()),'blockers'=>[],'warnings'=>[]]; }
    private function usedCategoryIds(): array { return Schema::hasTable('parts') ? DB::table('parts')->whereNotNull('category_id')->distinct()->pluck('category_id')->map(fn($v)=>(int)$v)->all() : []; }
    private function categorySamples(array $ids): array { if($ids===[]) return []; return DB::table('part_categories')->whereIn('id',array_slice($ids,0,self::MAX_SAMPLES))->get(['id as local_category_id','name as local_category_name','category_path as local_category_path'])->all(); }
    private function schemaBlockers(bool $live): array { $b=[]; foreach(['part_categories','marketplace_category_mappings'] as $t) if(!Schema::hasTable($t)) $b[]="$t table is missing."; foreach(['fulfillment_policy_id','shipping_group','is_blocked','block_reason'] as $c) if(Schema::hasTable('marketplace_category_mappings')&&!Schema::hasColumn('marketplace_category_mappings',$c)) $b[]="marketplace_category_mappings.$c column is missing."; return $b; }
    private function actionCount(array $a,string $ch,bool $blocked): int { return count(array_filter($a,fn($x)=>$x['channel']===$ch && (bool)$x['is_blocked']===$blocked)); }
    private function normalizeGroup($v): ?string { $v=trim((string)$v); if($v==='') return null; $u=mb_strtoupper($v); return str_contains($u,'DO WYWALENIA') ? 'DO WYWALENIA' : (in_array($v,['30','50','130'],true)?$v:$u); }
    private function emptyImportResponse(string $p,bool $exists,array $w,array $b): array { return ['ok'=>$b===[], 'csv_path'=>$p, 'csv_exists'=>$exists, 'csv_rows_total'=>0, 'csv_rows_with_shipping_group'=>0, 'csv_rows_30_count'=>0, 'csv_rows_50_count'=>0, 'csv_rows_130_count'=>0, 'csv_rows_do_wywalenia_count'=>0, 'matched_categories_count'=>0, 'unmatched_categories_count'=>0, 'conflict_count'=>0, 'would_update_ebay_de_shipping_count'=>0, 'would_update_ebay_fr_shipping_count'=>0, 'would_block_ebay_de_count'=>0, 'would_block_ebay_fr_count'=>0, 'would_clear_fulfillment_for_blocked_count'=>0, 'would_skip_count'=>0, 'count_per_shipping_group'=>[], 'count_per_fulfillment_policy_id'=>[], 'sample_matches'=>[], 'sample_unmatched'=>[], 'sample_conflicts'=>[], 'sample_skipped'=>[], 'sample_blocked_ebay_de'=>[], 'sample_blocked_ebay_fr'=>[], 'blockers'=>$b, 'warnings'=>$w]; }
    private function csvSample(array $r): array { return ['term_id'=>$r['term_id']??null,'name'=>$r['name']??null,'full_path'=>$r['full_path']??null,'shipping_group'=>$r['shipping_group']??null]; }
    private function sample(array &$a,array $v): void { if(count($a)<self::MAX_SAMPLES) $a[]=$v; }
    private function decode($v): array { if(is_array($v)) return $v; if(is_string($v)&&$v!=='') return json_decode($v,true) ?: []; return []; }
    private function norm($v): string { return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$v))); }
}

<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EbayLegacyCategoryMappingImportService
{
    private const CHANNELS = ['ebay', 'ebay_de', 'ebay_fr'];
    private const SOURCE = 'part_categories.legacy_payload.marketplace_mappings';

    public function dryRun(): array { return $this->build(false); }
    public function live(): array { return $this->build(true); }

    public function check(): array
    {
        if (! Schema::hasTable('marketplace_category_mappings')) return ['ok'=>false,'blockers'=>['marketplace_category_mappings table is missing.']];
        $used = $this->usedCategoryIds();
        $mappedUsed = DB::table('marketplace_category_mappings')->whereIn('channel', self::CHANNELS)->whereIn('local_category_id', $used)->distinct()->count('local_category_id');
        $missing = array_values(array_diff($used, DB::table('marketplace_category_mappings')->whereIn('channel', self::CHANNELS)->distinct()->pluck('local_category_id')->map(fn($v)=>(int)$v)->all()));
        return ['ok'=>true,'total'=>DB::table('marketplace_category_mappings')->count(),'per_channel_count'=>DB::table('marketplace_category_mappings')->select('channel',DB::raw('count(*) as c'))->groupBy('channel')->pluck('c','channel'),'ovoko_total'=>$this->channelCount('ovoko'),'allegro_main_total'=>$this->channelCount('allegro_main'),'ebay_total'=>$this->channelCount('ebay'),'ebay_de_total'=>$this->channelCount('ebay_de'),'ebay_fr_total'=>$this->channelCount('ebay_fr'),'ebay_de_blocked_count'=>$this->blockedCount('ebay_de'),'ebay_fr_blocked_count'=>$this->blockedCount('ebay_fr'),'used_local_categories_total'=>count($used),'used_local_categories_with_mapping'=>$mappedUsed,'used_local_categories_missing_mapping'=>count($missing),'blocked_count'=>DB::table('marketplace_category_mappings')->where('is_blocked', true)->count(),'missing_external_category_id_count'=>DB::table('marketplace_category_mappings')->whereIn('channel', self::CHANNELS)->whereNull('external_category_id')->count(),'sample_mappings'=>DB::table('marketplace_category_mappings')->whereIn('channel', self::CHANNELS)->orderBy('id')->limit(20)->get(),'sample_missing_used_categories'=>$this->missingSamples($missing),'sample_missing_ovoko'=>$this->missingChannelSamples('ovoko'),'sample_missing_allegro'=>$this->missingChannelSamples('allegro_main'),'sample_missing_ebay_de'=>$this->missingChannelSamples('ebay_de'),'sample_missing_ebay_fr'=>$this->missingChannelSamples('ebay_fr'),'warnings'=>['Read-only check; no marketplace API calls are performed.'],'blockers'=>[]];
    }

    private function build(bool $live): array
    {
        $blockers = [];
        if (! Schema::hasTable('part_categories')) $blockers[] = 'part_categories table is missing.';
        if ($live && ! Schema::hasTable('marketplace_category_mappings')) $blockers[] = 'marketplace_category_mappings table is missing; run migrations first.';
        if ($blockers) return ['ok'=>false,'mode'=>$live?'live':'dry_run','blockers'=>$blockers];

        $existing = Schema::hasTable('marketplace_category_mappings') ? DB::table('marketplace_category_mappings')->get()->keyBy(fn($r)=>$r->local_category_id.'|'.$r->channel) : collect();
        $records = $this->records(); $created=0; $updated=0; $skipped=0; $per=['ebay'=>0,'ebay_de'=>0,'ebay_fr'=>0]; $samples=[];
        foreach ($records as $record) {
            $key=$record['local_category_id'].'|'.$record['channel']; $exists=$existing->has($key);
            $exists ? $updated++ : $created++; $per[$record['channel']]++;
            if (count($samples)<20) $samples[]=$record;
            if ($live) { $save = $record; unset($save['products_count']); MarketplaceCategoryMapping::query()->updateOrCreate(['local_category_id'=>$record['local_category_id'],'channel'=>$record['channel']], $save + ['imported_at'=>now()]); }
        }
        $used=$this->usedCategoryIds(); $mappedIds=array_values(array_unique(array_column($records,'local_category_id'))); $missing=array_values(array_diff($used,$mappedIds));
        return ['ok'=>true,'mode'=>$live?'live':'dry_run','local_categories_total'=>DB::table('part_categories')->count(),'categories_with_legacy_payload'=>DB::table('part_categories')->whereNotNull('legacy_payload')->count(),'categories_with_any_ebay_mapping'=>count(array_unique($mappedIds)),'would_create_count'=>$live?null:$created,'would_update_count'=>$live?null:$updated,'would_skip_count'=>$live?null:$skipped,'created_count'=>$live?$created:null,'updated_count'=>$live?$updated:null,'skipped_count'=>$live?$skipped:null,($live?'imported_per_channel':'would_import_per_channel')=>$per,'used_categories_with_mapping_count'=>count(array_intersect($used,$mappedIds)),'used_categories_missing_mapping_count'=>count($missing),'ambiguous_mappings_count'=>0,'sample_records'=>$samples,'sample_missing_used_categories'=>$this->missingSamples($missing),'warnings'=>['Import uses only local part_categories.legacy_payload.marketplace_mappings; no eBay API calls, listing updates, product updates, or marketplace_listings writes are performed.'],'blockers'=>[]];
    }

    private function records(): array
    {
        $out=[]; $partCounts=$this->partCounts();
        DB::table('part_categories')->orderBy('id')->chunk(500, function($rows) use (&$out,$partCounts){ foreach($rows as $r){ $a=(array)$r; $payload=$this->decode($a['legacy_payload']??null); foreach(self::CHANNELS as $ch){ $m=$payload['marketplace_mappings'][$ch]??null; $id=is_array($m)?($m['category_id']??null):null; if(!filled($id)) continue; $out[]=['local_category_id'=>(int)$a['id'],'local_category_name'=>$a['name']??null,'local_category_path'=>$a['category_path']??($a['full_slug_path']??null),'old_category_id'=>$a['external_id']??null,'channel'=>$ch,'external_category_id'=>(string)$id,'external_category_name'=>$m['category_name']??($m['name']??null),'external_category_path'=>$m['category_path']??($m['path']??null),'source'=>self::SOURCE,'confidence'=>'high','metadata'=>['products_count'=>(int)($partCounts[$a['id']]??0)],'products_count'=>(int)($partCounts[$a['id']]??0)]; } }});
        return $out;
    }
    private function decode(mixed $v): array { if(is_array($v)) return $v; if(is_string($v)&&$v!=='') return json_decode($v,true) ?: []; return []; }
    private function partCounts(){ return Schema::hasTable('parts') ? DB::table('parts')->select('category_id',DB::raw('count(*) as c'))->whereNotNull('category_id')->groupBy('category_id')->pluck('c','category_id') : collect(); }
    private function usedCategoryIds(): array { return Schema::hasTable('parts') ? DB::table('parts')->whereNotNull('category_id')->distinct()->pluck('category_id')->map(fn($v)=>(int)$v)->all() : []; }
    private function missingSamples(array $ids): array { if($ids===[]) return []; return DB::table('part_categories')->whereIn('id',array_slice($ids,0,20))->get(['id as local_category_id','name as local_category_name','category_path as local_category_path'])->all(); }
    private function channelCount(string $channel): int { return (int) DB::table('marketplace_category_mappings')->where('channel',$channel)->count(); }
    private function blockedCount(string $channel): int { return (int) DB::table('marketplace_category_mappings')->where('channel',$channel)->where('is_blocked', true)->count(); }
    private function missingChannelSamples(string $channel): array { $mapped=DB::table('marketplace_category_mappings')->where('channel',$channel)->whereNotNull('external_category_id')->pluck('local_category_id')->map(fn($v)=>(int)$v)->all(); return $this->missingSamples(array_values(array_diff($this->usedCategoryIds(), $mapped))); }
}

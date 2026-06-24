<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class MarketplaceCategoryTreeImportService
{
    public const CHANNELS = ['ovoko', 'allegro_main', 'ebay_de'];

    public function previewOrImport(bool $write): array
    {
        $warnings = [];
        $channels = [];
        foreach (self::CHANNELS as $channel) {
            try { $rows = $this->fetch($channel); }
            catch (\Throwable $e) { $rows = []; $warnings[] = $channel.': '.$e->getMessage(); }
            $existing = Schema::hasTable('marketplace_categories') ? MarketplaceCategory::query()->where('channel', $channel)->get()->keyBy('external_category_id') : collect();
            $create = $update = [];
            foreach ($rows as $row) {
                $old = $existing->get($row['external_category_id']);
                if (! $old) $create[] = $row;
                elseif ($this->changed($old, $row)) $update[] = $row;
            }
            if ($write && Schema::hasTable('marketplace_categories')) $this->upsertRows($rows);
            $channels[$channel] = array_filter([
                'root' => $channel === 'allegro_main' ? 'Motoryzacja > Części samochodowe' : ($channel === 'ebay_de' ? 'Motors / Autoteile & Zubehör' : null),
                'fetched_count' => count($rows),
                'would_create_count' => count($create),
                'would_update_count' => count($update),
                'sample_would_create' => array_slice($create, 0, 10),
            ], fn ($v) => $v !== null);
        }
        return ['ok' => true, 'dry_run' => ! $write, 'local_update' => $write, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false, 'channels' => $channels, 'warnings' => $warnings];
    }

    public function backfillEbayDe(bool $write): array
    {
        $channels = ['ebay_de', 'ebay'];
        $mappings = DB::table('marketplace_category_mappings')->whereIn('channel', $channels)->whereNotNull('external_category_id')->get();
        $tree = MarketplaceCategory::query()->where('channel', 'ebay_de')->get()->keyBy('external_category_id');
        $would = []; $missing = [];
        foreach ($mappings as $m) {
            $cat = $tree->get((string) $m->external_category_id);
            if (! $cat) { $missing[] = ['mapping_id' => $m->id, 'channel' => $m->channel, 'local_category_id' => $m->local_category_id, 'external_category_id' => $m->external_category_id]; continue; }
            if (blank($m->external_category_name) || blank($m->external_category_path)) {
                $row = ['mapping_id' => $m->id, 'channel' => $m->channel, 'local_category_id' => $m->local_category_id, 'external_category_id' => $m->external_category_id, 'external_category_name' => $cat->name, 'external_category_path' => $cat->full_path];
                $would[] = $row;
                if ($write) DB::table('marketplace_category_mappings')->where('id', $m->id)->update(['external_category_name' => $cat->name, 'external_category_path' => $cat->full_path, 'updated_at' => now()]);
            }
        }
        return ['ok' => true, 'dry_run' => ! $write, 'local_update' => $write, 'mapping_count' => $mappings->count(), 'would_backfill_count' => count($would), 'not_found_in_tree_count' => count($missing), 'sample_would_backfill' => array_slice($would, 0, 20), 'sample_not_found' => array_slice($missing, 0, 20)];
    }

    private function fetch(string $channel): array
    {
        return match ($channel) {
            'ovoko' => $this->normalizeOvoko(app(MarketplaceApiManager::class)->client('ovoko')->fetchCategories(60)['categories'] ?? []),
            'allegro_main' => $this->fetchAllegro(),
            'ebay_de' => $this->fetchEbayDe(),
        };
    }

    private function normalizeOvoko(array $rows): array
    {
        $base = collect($rows)->map(function (array $r) {
            $id = (string) ($r['id'] ?? $r['category_id'] ?? $r['categoryId'] ?? '');
            $parent = $r['parent_id'] ?? $r['parentId'] ?? $r['parent_category_id'] ?? null;
            $name = (string) ($r['pl'] ?? $r['name'] ?? $r['category_name'] ?? $r['en'] ?? $id);
            return ['channel'=>'ovoko','external_category_id'=>$id,'parent_external_category_id'=>filled($parent) && (string)$parent !== '0' ? (string)$parent : null,'level'=>0,'name'=>$name,'full_path'=>(string)($r['category_title_path'] ?? $r['category_path'] ?? $r['path'] ?? $name),'raw_payload'=>$r,'active'=>true,'imported_at'=>now()];
        })->filter(fn($r)=>filled($r['external_category_id']))->values();
        return $this->withLevels($base->all());
    }

    private function fetchAllegro(): array
    {
        $account = MarketplaceAccount::query()->where('code','allegro_main')->first(); $token = (string) data_get($account, 'api_credentials.access_token'); $base = rtrim((string) $account?->api_base_url, '/');
        if ($base === '' || $token === '') return [];
        $all = []; $queue = [[null, '', 0]];
        while ($queue) { [$parent,$path,$level] = array_shift($queue); $res = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(30)->get($base.'/sale/categories', array_filter(['parent.id'=>$parent])); foreach (($res->json('categories') ?: []) as $c) { if (!is_array($c)) continue; $name=(string)($c['name']??$c['id']); $full=trim($path === '' ? $name : $path.' > '.$name); $row=['channel'=>'allegro_main','external_category_id'=>(string)$c['id'],'parent_external_category_id'=>$parent,'level'=>$level,'name'=>$name,'full_path'=>$full,'raw_payload'=>$c,'active'=>true,'imported_at'=>now()]; if (str_starts_with($full, 'Motoryzacja > Części samochodowe') || str_starts_with('Motoryzacja > Części samochodowe', $full)) { $all[]=$row; if (!($c['leaf']??false)) $queue[]=[(string)$c['id'],$full,$level+1]; } } }
        return $all;
    }

    private function fetchEbayDe(): array
    {
        $account = MarketplaceAccount::query()->where('code','ebay_de')->first(); $token=(string)data_get($account,'api_credentials.access_token'); $base=rtrim((string)$account?->api_base_url,'/'); if ($base===''||$token==='') return [];
        $headers=['X-EBAY-C-MARKETPLACE-ID'=>'EBAY_DE']; $tree=Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(30)->get($base.'/commerce/taxonomy/v1/get_default_category_tree_id',['marketplace_id'=>'EBAY_DE'])->json('categoryTreeId'); if (!$tree) return [];
        $json=Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(60)->get($base.'/commerce/taxonomy/v1/category_tree/'.$tree)->json();
        $root=$json['rootCategoryNode'] ?? null; return is_array($root) ? $this->flattenEbay([$root]) : [];
    }

    private function flattenEbay(array $nodes, ?string $parent = null, string $path = '', int $level = 0): array { $out=[]; foreach($nodes as $n){ $cat=$n['category']??[]; $id=(string)($cat['categoryId']??''); if($id==='') continue; $name=(string)($cat['categoryName']??$id); $full=trim($path===''?$name:$path.' > '.$name); $row=['channel'=>'ebay_de','external_category_id'=>$id,'parent_external_category_id'=>$parent,'level'=>$level,'name'=>$name,'full_path'=>$full,'raw_payload'=>$n,'active'=>true,'imported_at'=>now()]; if(str_contains($full,'Auto & Motorrad')||str_contains($full,'Autoteile')||str_contains($full,'Motors')) $out[]=$row; $children=$n['childCategoryTreeNodes']??[]; if(is_array($children)) $out=array_merge($out,$this->flattenEbay($children,$id,$full,$level+1)); } return $out; }
    private function withLevels(array $rows): array { $by=collect($rows)->keyBy('external_category_id'); return array_map(function($r)use($by){$level=0;$p=$r['parent_external_category_id'];while($p&&$by->has($p)&&$level<20){$level++;$p=$by[$p]['parent_external_category_id'];}$r['level']=$level;return $r;},$rows); }
    private function changed(MarketplaceCategory $old, array $row): bool { return $old->parent_external_category_id !== $row['parent_external_category_id'] || (int)$old->level !== (int)$row['level'] || $old->name !== $row['name'] || $old->full_path !== $row['full_path']; }
    private function upsertRows(array $rows): void { foreach(array_chunk($rows,500) as $chunk) MarketplaceCategory::query()->upsert(array_map(fn($r)=>array_merge($r,['created_at'=>now(),'updated_at'=>now()]),$chunk), ['channel','external_category_id'], ['parent_external_category_id','level','name','full_path','raw_payload','active','imported_at','updated_at']); }
}

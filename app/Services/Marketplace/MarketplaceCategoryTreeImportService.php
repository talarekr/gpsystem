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
    private const ALLEGRO_WANTED_ROOT = 'Motoryzacja > Części samochodowe';
    private const EBAY_DE_WANTED_ROOT = 'Motors / Autoteile & Zubehör';

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
                'root' => $channel === 'allegro_main' ? self::ALLEGRO_WANTED_ROOT : ($channel === 'ebay_de' ? self::EBAY_DE_WANTED_ROOT : null),
                'fetched_count' => count($rows),
                'would_create_count' => count($create),
                'would_update_count' => count($update),
                'sample_would_create' => array_slice($create, 0, 10),
            ], fn ($v) => $v !== null);
        }
        return ['ok' => true, 'dry_run' => ! $write, 'local_update' => $write, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false, 'channels' => $channels, 'warnings' => $warnings];
    }

    public function debugFetch(): array
    {
        $warnings = [];
        return [
            'ok' => true,
            'dry_run' => true,
            'local_update' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'channels' => [
                'ovoko' => $this->debugOvoko($warnings),
                'allegro_main' => $this->debugAllegro($warnings),
                'ebay_de' => $this->debugEbayDe($warnings),
            ],
            'warnings' => $warnings,
        ];
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

    private function debugOvoko(array &$warnings): array
    {
        $base = ['configured' => false, 'fetch_ok' => false, 'raw_count' => 0, 'sample_roots' => [], 'sample_level_2' => [], 'sample_level_3' => [], 'error' => null];
        try {
            $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
            $base['configured'] = $this->accountConfigured($account);
            $rows = $this->normalizeOvoko(app(MarketplaceApiManager::class)->client('ovoko')->fetchCategories(60)['categories'] ?? []);
            $base['fetch_ok'] = true; $base['raw_count'] = count($rows);
            $base['sample_roots'] = $this->sampleRows($rows, fn ($r) => (int) $r['level'] === 0);
            $base['sample_level_2'] = $this->sampleRows($rows, fn ($r) => (int) $r['level'] === 1);
            $base['sample_level_3'] = $this->sampleRows($rows, fn ($r) => (int) $r['level'] === 2);
        } catch (\Throwable $e) { $base['error'] = $e->getMessage(); $warnings[] = 'ovoko: '.$e->getMessage(); }
        return $base;
    }

    private function debugAllegro(array &$warnings): array
    {
        $base = ['configured' => false, 'fetch_ok' => false, 'raw_count' => 0, 'root_candidates' => [], 'wanted_root' => self::ALLEGRO_WANTED_ROOT, 'wanted_root_found' => false, 'children_count' => 0, 'sample_children' => [], 'error' => null];
        try {
            $account = MarketplaceAccount::query()->where('code', 'allegro_main')->first(); $base['configured'] = $this->accountConfigured($account);
            [$roots, $children] = $this->allegroRootAndSubtreeDiagnostics($account);
            $rows = $this->fetchAllegro();
            $base['fetch_ok'] = true; $base['raw_count'] = count($rows); $base['root_candidates'] = $roots;
            $base['wanted_root_found'] = count($rows) > 0; $base['children_count'] = count($children); $base['sample_children'] = array_slice($children, 0, 20);
        } catch (\Throwable $e) { $base['error'] = $e->getMessage(); $warnings[] = 'allegro_main: '.$e->getMessage(); }
        return $base;
    }

    private function debugEbayDe(array &$warnings): array
    {
        $base = ['configured' => false, 'fetch_ok' => false, 'taxonomy_ok' => false, 'raw_count' => 0, 'root_candidates' => [], 'wanted_root' => self::EBAY_DE_WANTED_ROOT, 'wanted_root_found' => false, 'children_count' => 0, 'sample_children' => [], 'sample_existing_mapping_ids_lookup' => [], 'error' => null];
        try {
            $account = MarketplaceAccount::query()->where('code', 'ebay_de')->first(); $base['configured'] = $this->accountConfigured($account);
            [$treeId, $root, $rootCandidates] = $this->ebayTree($account);
            $base['taxonomy_ok'] = filled($treeId) && is_array($root); $base['root_candidates'] = $rootCandidates;
            $rows = is_array($root) ? $this->flattenEbayWantedSubtree([$root]) : [];
            $base['fetch_ok'] = true; $base['raw_count'] = count($rows); $base['wanted_root_found'] = count($rows) > 0;
            $base['children_count'] = max(0, count($rows) - 1); $base['sample_children'] = array_slice(array_map(fn ($r) => $this->rowSummary($r), $rows), 0, 20);
            $base['sample_existing_mapping_ids_lookup'] = $this->lookupExistingEbayMappings($rows);
        } catch (\Throwable $e) { $base['error'] = $e->getMessage(); $warnings[] = 'ebay_de: '.$e->getMessage(); }
        return $base;
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
        $account = MarketplaceAccount::query()->where('code','allegro_main')->first();
        [$roots] = $this->allegroRootAndSubtreeDiagnostics($account);
        $wantedRoot = collect($roots)->first(fn ($r) => $this->isAllegroMotoryzacjaRoot((string) $r['name']));
        if (! $wantedRoot) return [];
        $rootPath = (string) $wantedRoot['name'];
        $rootRow = $this->marketplaceRow('allegro_main', (string) $wantedRoot['id'], null, 0, $rootPath, $rootPath, $wantedRoot['raw'] ?? []);
        $firstLevel = $this->fetchAllegroChildren((string) $wantedRoot['id'], $rootPath, 1);
        $partsRoot = collect($firstLevel)->first(fn ($row) => $this->isAllegroPartsRoot((string) $row['name'], (string) $row['full_path']));
        if (! $partsRoot) return [$rootRow, ...$firstLevel];
        return array_merge([$rootRow, $partsRoot], $this->fetchAllegroDescendants((string) $partsRoot['external_category_id'], (string) $partsRoot['full_path'], 2));
    }

    private function allegroRootAndSubtreeDiagnostics(?MarketplaceAccount $account): array
    {
        $token = (string) data_get($account, 'api_credentials.access_token'); $base = rtrim((string) $account?->api_base_url, '/');
        if ($base === '' || $token === '') return [[], []];
        $res = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(30)->get($base.'/sale/categories');
        $roots = [];
        foreach (($res->json('categories') ?: []) as $c) if (is_array($c)) $roots[] = ['id' => (string)($c['id'] ?? ''), 'name' => (string)($c['name'] ?? ''), 'leaf' => (bool)($c['leaf'] ?? false), 'raw' => $c];
        $wanted = collect($roots)->first(fn ($r) => $this->isAllegroMotoryzacjaRoot((string) $r['name']));
        $children = $wanted ? array_map(fn ($r) => $this->rowSummary($r), $this->fetchAllegroChildren((string) $wanted['id'], (string) $wanted['name'], 1)) : [];
        return [array_map(fn ($r) => array_diff_key($r, ['raw' => true]), $roots), $children];
    }


    private function fetchAllegroChildren(string $parentId, string $path, int $level): array
    {
        $account = MarketplaceAccount::query()->where('code','allegro_main')->first(); $token = (string) data_get($account, 'api_credentials.access_token'); $base = rtrim((string) $account?->api_base_url, '/');
        if ($base === '' || $token === '') return [];
        $res = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(30)->get($base.'/sale/categories', ['parent.id' => $parentId]);
        $out = [];
        foreach (($res->json('categories') ?: []) as $c) {
            if (! is_array($c)) continue; $id = (string)($c['id'] ?? ''); if ($id === '') continue;
            $name = (string)($c['name'] ?? $id); $out[] = $this->marketplaceRow('allegro_main', $id, $parentId, $level, $name, $path.' > '.$name, $c);
        }
        return $out;
    }

    private function fetchAllegroDescendants(string $parentId, string $path, int $level): array
    {
        $account = MarketplaceAccount::query()->where('code','allegro_main')->first(); $token = (string) data_get($account, 'api_credentials.access_token'); $base = rtrim((string) $account?->api_base_url, '/');
        if ($base === '' || $token === '') return [];
        $out = []; $queue = [[$parentId, $path, $level]];
        while ($queue) {
            [$parent, $parentPath, $lvl] = array_shift($queue);
            $res = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(30)->get($base.'/sale/categories', ['parent.id' => $parent]);
            foreach (($res->json('categories') ?: []) as $c) {
                if (! is_array($c)) continue; $id = (string)($c['id'] ?? ''); if ($id === '') continue;
                $name = (string)($c['name'] ?? $id); $full = $parentPath.' > '.$name;
                $row = $this->marketplaceRow('allegro_main', $id, $parent, $lvl, $name, $full, $c); $out[] = $row;
                if (! ($c['leaf'] ?? false)) $queue[] = [$id, $full, $lvl + 1];
            }
        }
        return $out;
    }

    private function fetchEbayDe(): array
    {
        $account = MarketplaceAccount::query()->where('code','ebay_de')->first();
        [, $root] = $this->ebayTree($account);
        return is_array($root) ? $this->flattenEbayWantedSubtree([$root]) : [];
    }

    private function ebayTree(?MarketplaceAccount $account): array
    {
        $token=(string)data_get($account,'api_credentials.access_token'); $base=rtrim((string)$account?->api_base_url,'/'); if ($base===''||$token==='') return [null, null, []];
        $headers=['X-EBAY-C-MARKETPLACE-ID'=>'EBAY_DE'];
        $tree=Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(30)->get($base.'/commerce/taxonomy/v1/get_default_category_tree_id',['marketplace_id'=>'EBAY_DE'])->json('categoryTreeId'); if (!$tree) return [null, null, []];
        $json=Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(60)->get($base.'/commerce/taxonomy/v1/category_tree/'.$tree)->json();
        $root=$json['rootCategoryNode'] ?? null;
        $candidates = is_array($root) ? array_merge([['category_tree_id' => (string) $tree] + $this->ebayNodeSummary($root)], array_slice(array_map(fn ($n) => ['category_tree_id' => (string) $tree] + $this->ebayNodeSummary($n), $root['childCategoryTreeNodes'] ?? []), 0, 50)) : [['category_tree_id' => (string) $tree]];
        return [(string) $tree, $root, $candidates];
    }

    private function flattenEbayWantedSubtree(array $nodes, ?string $parent = null, string $path = '', int $level = 0, bool $insideWanted = false): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $cat=$n['category']??[]; $id=(string)($cat['categoryId']??''); if($id==='') continue;
            $name=(string)($cat['categoryName']??$id); $full=trim($path===''?$name:$path.' > '.$name);
            $wanted = $insideWanted || $this->isEbayDeAutomotiveRoot($name, $full);
            $row=$this->marketplaceRow('ebay_de', $id, $parent, $level, $name, $full, $n);
            if ($wanted) $out[]=$row;
            $children=$n['childCategoryTreeNodes']??[];
            if(is_array($children)) $out=array_merge($out,$this->flattenEbayWantedSubtree($children,$id,$full,$level+1,$wanted));
        }
        return $out;
    }

    private function lookupExistingEbayMappings(array $rows): array
    {
        if (! Schema::hasTable('marketplace_category_mappings')) return [];
        $byId = collect($rows)->keyBy('external_category_id');
        return DB::table('marketplace_category_mappings')->whereIn('channel', ['ebay', 'ebay_de'])->whereNotNull('external_category_id')->select('id','channel','local_category_id','external_category_id')->distinct()->limit(20)->get()->map(function ($m) use ($byId) {
            $cat = $byId->get((string) $m->external_category_id);
            return ['mapping_id' => $m->id, 'channel' => $m->channel, 'local_category_id' => $m->local_category_id, 'external_category_id' => (string) $m->external_category_id, 'found_in_fetched_tree' => $cat !== null, 'name' => $cat['name'] ?? null, 'path' => $cat['full_path'] ?? null];
        })->all();
    }

    private function marketplaceRow(string $channel, string $id, ?string $parent, int $level, string $name, string $fullPath, array $raw): array { return ['channel'=>$channel,'external_category_id'=>$id,'parent_external_category_id'=>$parent,'level'=>$level,'name'=>$name,'full_path'=>$fullPath,'raw_payload'=>$raw,'active'=>true,'imported_at'=>now()]; }
    private function accountConfigured(?MarketplaceAccount $account): bool { return (bool) ($account && $account->api_enabled && filled($account->api_base_url) && filled(data_get($account, 'api_credentials.access_token'))); }
    private function normalizeText(string $value): string { return str($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value(); }
    private function isAllegroMotoryzacjaRoot(string $name): bool { $n = $this->normalizeText($name); return str_contains($n, 'motoryzacja') || str_contains($n, 'samochod'); }
    private function isAllegroPartsRoot(string $name, string $path): bool { $n = $this->normalizeText($name.' '.$path); return str_contains($n, 'czesci samochodowe') || (str_contains($n, 'czesci') && str_contains($n, 'auto')); }
    private function isEbayDeAutomotiveRoot(string $name, string $path): bool { $n = $this->normalizeText($name.' '.$path); return (str_contains($n, 'auto') || str_contains($n, 'motor') || str_contains($n, 'fahrzeug')) && (str_contains($n, 'teile') || str_contains($n, 'zubehor') || str_contains($n, 'parts')); }
    private function rowSummary(array $r): array { return ['id' => (string)($r['external_category_id'] ?? $r['id'] ?? ''), 'parent_id' => $r['parent_external_category_id'] ?? null, 'name' => (string)($r['name'] ?? ''), 'path' => (string)($r['full_path'] ?? $r['path'] ?? $r['name'] ?? ''), 'level' => (int)($r['level'] ?? 0)]; }
    private function ebayNodeSummary(array $n): array { $cat = $n['category'] ?? []; return ['id' => (string)($cat['categoryId'] ?? ''), 'name' => (string)($cat['categoryName'] ?? ''), 'children_count' => count($n['childCategoryTreeNodes'] ?? [])]; }
    private function sampleRows(array $rows, callable $filter): array { return array_slice(array_values(array_map(fn ($r) => $this->rowSummary($r), array_filter($rows, $filter))), 0, 20); }
    private function withLevels(array $rows): array { $by=collect($rows)->keyBy('external_category_id'); return array_map(function($r)use($by){$level=0;$p=$r['parent_external_category_id'];while($p&&$by->has($p)&&$level<20){$level++;$p=$by[$p]['parent_external_category_id'];}$r['level']=$level;return $r;},$rows); }
    private function changed(MarketplaceCategory $old, array $row): bool { return $old->parent_external_category_id !== $row['parent_external_category_id'] || (int)$old->level !== (int)$row['level'] || $old->name !== $row['name'] || $old->full_path !== $row['full_path']; }
    private function upsertRows(array $rows): void { foreach(array_chunk($rows,500) as $chunk) MarketplaceCategory::query()->upsert(array_map(fn($r)=>array_merge($r,['created_at'=>now(),'updated_at'=>now()]),$chunk), ['channel','external_category_id'], ['parent_external_category_id','level','name','full_path','raw_payload','active','imported_at','updated_at']); }
}

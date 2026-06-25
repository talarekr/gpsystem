<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SuggestOvokoCategoryMappingsFromLocalTreeController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $filters = [
            'only_public' => $request->boolean('only_public', true),
            'only_with_products' => $request->boolean('only_with_products', true),
            'leaf_only' => $request->boolean('leaf_only', true),
            'only_missing_ovoko' => $request->boolean('only_missing_ovoko', true),
            'sample_limit' => max(0, min(1000, (int) $request->query('sample_limit', 200))),
            'min_score' => max(0.0, min(1.0, (float) $request->query('min_score', 0.85))),
            'category_id' => $request->query('category_id'),
            'include_existing' => $request->boolean('include_existing', false),
            'diagnostics' => $request->boolean('diagnostics', false),
        ];
        $diagnostics = ['ovoko_table_used' => null, 'ovoko_rows_loaded' => 0, 'ovoko_leaf_rows_loaded' => 0, 'local_categories_checked' => 0, 'filters' => $filters, 'errors_sample' => []];

        try {
            [$ovokoRows, $diagnostics] = $this->loadOvokoRows($diagnostics);
            $localRows = $this->loadLocalRows($filters);
            $diagnostics['local_categories_checked'] = $localRows->count();

            $items = [];
            foreach ($localRows as $local) {
                $existing = $this->existingOvokoMapping((int) $local->id);
                if ($existing && ! $filters['include_existing']) {
                    continue;
                }
                $items[] = $existing
                    ? $this->existingItem($local, $existing)
                    : $this->suggestItem($local, $ovokoRows, $filters['min_score']);
                if (count($items) >= $filters['sample_limit']) {
                    break;
                }
            }

            $summary = $this->summary($items);

            return response()->json($this->flags() + [
                'ok' => true,
                'items' => $items,
                'suggested_mappings' => $items,
                'summary' => $summary + ['total_matching_count' => count($items)],
                'diagnostics' => $filters['diagnostics'] ? $diagnostics : array_intersect_key($diagnostics, array_flip(['ovoko_table_used', 'ovoko_rows_loaded', 'ovoko_leaf_rows_loaded', 'local_categories_checked', 'filters', 'errors_sample'])),
            ]);
        } catch (Throwable $e) {
            $diagnostics['errors_sample'][] = ['type' => 'critical_error', 'message' => $e->getMessage()];

            return response()->json($this->flags() + ['ok' => false, 'items' => [], 'summary' => $this->summary([]) + ['total_matching_count' => 0], 'diagnostics' => $diagnostics]);
        }
    }

    private function flags(): array
    {
        return ['read_only' => true, 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false, 'products_changed' => false, 'offers_changed' => false, 'mappings_changed' => false];
    }

    private function loadLocalRows(array $filters)
    {
        $q = DB::table('part_categories as c')
            ->select('c.*')
            ->selectSub(fn (Builder $q) => $q->from('parts')->selectRaw('count(*)')->whereColumn('parts.category_id', 'c.id'), 'products_count');
        if ($filters['only_public'] && Schema::hasColumn('part_categories', 'is_visible')) $q->where('c.is_visible', true);
        if ($filters['category_id'] !== null) $q->where('c.id', $filters['category_id']);
        if ($filters['leaf_only'] && Schema::hasColumn('part_categories', 'parent_id')) $q->whereNotExists(fn (Builder $q) => $q->from('part_categories as ch')->whereColumn('ch.parent_id', 'c.id'));
        if ($filters['only_with_products']) $q->where(function (Builder $q): void { $q->whereExists(fn (Builder $p) => $p->from('parts')->whereColumn('parts.category_id', 'c.id')); if (Schema::hasColumn('part_categories', 'descendants_products_count')) $q->orWhere('c.descendants_products_count', '>', 0); });
        if ($filters['only_missing_ovoko']) $q->whereNotExists(fn (Builder $m) => $m->from('marketplace_category_mappings')->whereColumn('marketplace_category_mappings.local_category_id', 'c.id')->where('channel', 'ovoko'));
        return $q->orderBy('c.id')->limit(5000)->get();
    }

    private function loadOvokoRows(array $diagnostics): array
    {
        foreach (['ovoko_categories', 'marketplace_categories', 'marketplace_category_trees', 'external_categories', 'category_marketplace_trees', 'marketplace_taxonomy_categories'] as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table);
            $id = $this->firstColumn($columns, ['external_category_id', 'ovoko_category_id', 'category_id', 'external_id', 'id']);
            $name = $this->firstColumn($columns, ['name', 'category_name', 'external_category_name', 'title']);
            if (! $id || ! $name) continue;
            $path = $this->firstColumn($columns, ['full_path', 'path', 'category_path', 'external_category_path']);
            $parent = $this->firstColumn($columns, ['parent_external_category_id', 'parent_id']);
            $leaf = $this->firstColumn($columns, ['is_leaf', 'leaf']);
            $q = DB::table($table);
            foreach (['channel', 'marketplace', 'source'] as $channelColumn) if (in_array($channelColumn, $columns, true)) $q->whereRaw('LOWER('.$channelColumn.') = ?', ['ovoko']);
            $rows = $q->limit(20000)->get()->map(function ($row) use ($id, $name, $path, $parent, $leaf) {
                return ['ovoko_category_id' => (string) $row->{$id}, 'ovoko_category_name' => (string) $row->{$name}, 'ovoko_category_path' => $path ? (string) ($row->{$path} ?? '') : '', 'parent_id' => $parent ? (string) ($row->{$parent} ?? '') : null, 'is_leaf' => $leaf ? (bool) $row->{$leaf} : null];
            })->all();
            $idsWithParent = array_filter(array_column($rows, 'parent_id'));
            foreach ($rows as &$row) if ($row['is_leaf'] === null) $row['is_leaf'] = ! in_array($row['ovoko_category_id'], $idsWithParent, true);
            unset($row);
            $diagnostics['ovoko_table_used'] = $table; $diagnostics['ovoko_rows_loaded'] = count($rows); $diagnostics['ovoko_leaf_rows_loaded'] = count(array_filter($rows, fn ($r) => $r['is_leaf']));
            return [$rows, $diagnostics];
        }
        $diagnostics['errors_sample'][] = ['type' => 'ovoko_tree_not_found', 'message' => 'No supported local Ovoko category table was found.'];
        return [[], $diagnostics];
    }

    private function suggestItem(object $local, array $ovokoRows, float $minScore): array
    {
        $localName = (string) ($local->name ?? ''); $localPath = (string) ($local->category_path ?: ($local->full_slug_path ?? $localName));
        $candidates = [];
        foreach ($ovokoRows as $ovoko) {
            if (! $ovoko['is_leaf']) continue;
            $score = $this->score($localName, $localPath, $ovoko['ovoko_category_name'], $ovoko['ovoko_category_path']);
            if ($score >= max(0.55, $minScore - 0.25)) $candidates[] = $ovoko + ['score' => $score, 'reason' => $this->reason($score)];
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($candidates, 0, 3);
        $best = $top[0] ?? null; $ambiguous = $best && isset($top[1]) && abs($best['score'] - $top[1]['score']) < 0.04;
        $status = ! $best || $best['score'] < $minScore ? 'no_match' : ($ambiguous ? 'ambiguous' : 'suggested');
        $confidence = $status === 'suggested' && $best['score'] >= 0.95 ? 'high' : ($best && $best['score'] >= $minScore ? 'medium' : 'low');
        if ($ambiguous) $confidence = 'medium';
        return ['local_category_id' => (int) $local->id, 'local_category_name' => $localName, 'category_path' => $localPath, 'products_count' => (int) $local->products_count, 'suggested_ovoko_category_id' => $status === 'no_match' ? null : $best['ovoko_category_id'], 'suggested_ovoko_category_name' => $status === 'no_match' ? null : $best['ovoko_category_name'], 'suggested_ovoko_category_path' => $status === 'no_match' ? null : $best['ovoko_category_path'], 'score' => $best ? round($best['score'], 4) : 0, 'confidence' => $confidence, 'status' => $status, 'candidates' => $top, 'reason' => $ambiguous ? 'Multiple close Ovoko candidates require review.' : ($best['reason'] ?? 'No candidate reached the minimum score.')];
    }

    private function score(string $localName, string $localPath, string $ovokoName, string $ovokoPath): float
    {
        $ln = $this->normalize($localName); $on = $this->normalize($ovokoName); $lp = $this->normalize($localPath); $op = $this->normalize($ovokoPath);
        similar_text($ln, $on, $namePct); similar_text($lp, $op, $pathPct);
        $score = max($ln === $on ? 0.97 : 0, $this->aliasMatch($ln, $on) ? 0.94 : 0, $namePct / 100 * 0.82 + $pathPct / 100 * 0.18);
        if ($ln !== '' && $op !== '' && str_ends_with($op, $ln)) $score = max($score, 0.9);
        if (($ln === $on || $this->aliasMatch($ln, $on)) && $lp !== '' && $op !== '' && (str_contains($op, $ln) || str_contains($lp, $on))) $score = min(1, $score + 0.03);
        return $score;
    }

    private function normalize(string $v): string
    {
        $v = strtr(mb_strtolower(trim($v)), ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z']);
        $v = preg_replace('/[^a-z0-9]+/u', ' ', $v) ?? $v;
        $words = array_map(fn ($w) => rtrim($w, 'yiuaeow'), array_filter(explode(' ', trim($v))));
        sort($words);
        return implode(' ', $words);
    }

    private function aliasMatch(string $a, string $b): bool
    {
        foreach ([['drzw przedn','przedn drzw'],['drzw tyln','tyln drzw'],['lamp przedn','reflektor'],['lamp tyln','tyln lamp'],['zderzak przedn','przedn zderzak'],['zderzak tyln','tyln zderzak'],['mask','pokryw silnik'],['klap tyln','pokryw bagaznik'],['maglownic','przekladn kierownicz']] as [$x, $y]) if (($a === $x && $b === $y) || ($a === $y && $b === $x)) return true;
        return false;
    }

    private function existingOvokoMapping(int $id): ?object { return Schema::hasTable('marketplace_category_mappings') ? DB::table('marketplace_category_mappings')->where('local_category_id', $id)->where('channel', 'ovoko')->first() : null; }
    private function existingItem(object $local, object $m): array { return ['local_category_id'=>(int)$local->id,'local_category_name'=>(string)$local->name,'category_path'=>(string)($local->category_path ?? ''),'products_count'=>(int)$local->products_count,'suggested_ovoko_category_id'=>$m->external_category_id,'suggested_ovoko_category_name'=>$m->external_category_name,'suggested_ovoko_category_path'=>$m->external_category_path,'score'=>1,'confidence'=>'high','status'=>'existing_mapping','candidates'=>[],'reason'=>'Existing local Ovoko mapping found.']; }
    private function firstColumn(array $columns, array $names): ?string { foreach ($names as $name) if (in_array($name, $columns, true)) return $name; return null; }
    private function reason(float $score): string { return $score >= 0.95 ? 'Exact or near-exact normalized name/path match.' : ($score >= 0.85 ? 'Strong normalized name match with usable path context.' : 'Fuzzy normalized name match.'); }
    private function summary(array $items): array { return ['suggested_count'=>count(array_filter($items, fn($i)=>$i['status']==='suggested')),'high_confidence_count'=>count(array_filter($items, fn($i)=>$i['confidence']==='high')),'medium_confidence_count'=>count(array_filter($items, fn($i)=>$i['confidence']==='medium')),'ambiguous_count'=>count(array_filter($items, fn($i)=>$i['status']==='ambiguous')),'no_match_count'=>count(array_filter($items, fn($i)=>$i['status']==='no_match')),'existing_mapping_count'=>count(array_filter($items, fn($i)=>$i['status']==='existing_mapping'))]; }
}

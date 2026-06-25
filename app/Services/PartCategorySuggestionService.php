<?php

namespace App\Services;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Support\Str;

class PartCategorySuggestionService
{
    /** @return array<string, mixed> */
    public function suggestCategoryFromTitle(string $title, ?int $ignorePartId = null, int $limit = 3): array
    {
        $limit = max(1, min(5, $limit));
        $terms = $this->expandedTerms($title);

        if ($terms === []) {
            return $this->emptyResponse('Brak wystarczających fraz części w tytule.');
        }

        $matches = Part::query()
            ->with('category:id,name,parent_id,category_path,full_slug_path')
            ->select(['id', 'category_id', 'name', 'sku', 'part_number', 'oem_number', 'manufacturer_code'])
            ->whereNotNull('category_id')
            ->when($ignorePartId, fn ($query) => $query->whereKeyNot($ignorePartId))
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term => $weight) {
                    if (Str::length($term) < 3) continue;
                    $like = '%'.$term.'%';
                    $query->orWhere('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('part_number', 'like', $like)->orWhere('oem_number', 'like', $like)->orWhere('manufacturer_code', 'like', $like);
                    $ascii = Str::ascii($term);
                    if ($ascii !== $term) $query->orWhere('name', 'like', '%'.$ascii.'%');
                }
            })
            ->latest('id')
            ->limit(60)
            ->get();

        if ($matches->isEmpty()) {
            return $this->emptyResponse('Brak podobnych części z finalną kategorią.');
        }

        $scored = [];
        foreach ($matches as $part) {
            if (! $part->category) continue;
            $haystack = $this->normalize(implode(' ', array_filter([$part->name, $part->sku, $part->part_number, $part->oem_number, $part->manufacturer_code])));
            $matchedTerms = [];
            $score = 0.0;
            foreach ($terms as $term => $weight) {
                if (str_contains($haystack, $term) || str_contains($haystack, Str::ascii($term))) {
                    $matchedTerms[] = $term;
                    $score += $weight;
                }
            }
            if ($score <= 0) continue;
            $cid = (int) $part->category_id;
            $scored[$cid] ??= ['category' => $part->category, 'score' => 0.0, 'matched_terms' => [], 'parts' => []];
            $scored[$cid]['score'] += $score;
            $scored[$cid]['matched_terms'] = array_values(array_unique(array_merge($scored[$cid]['matched_terms'], $matchedTerms)));
            $scored[$cid]['parts'][$part->id] = ['id' => $part->id, 'title' => $part->name];
        }

        $suggestions = collect($scored)->map(function (array $row, int $categoryId): array {
            $category = $row['category'];
            $partCount = count($row['parts']);
            return [
                'category_id' => $categoryId,
                'category_name' => $category->name,
                'category_path' => $this->categoryPath($category),
                'score' => round($row['score'] + ($partCount * 2), 2),
                'matched_terms' => array_values(array_slice($row['matched_terms'], 0, 8)),
                'matched_parts_count' => $partCount,
                'matched_parts' => array_values(array_slice($row['parts'], 0, 3, true)),
            ];
        })->sortByDesc('score')->values();

        if ($suggestions->isEmpty()) return $this->emptyResponse('Brak punktowanych dopasowań kategorii.');

        $top = $suggestions->first();
        $second = $suggestions->get(1);
        $auto = $top['score'] >= 12 && ($top['matched_parts_count'] >= 2 || $top['score'] >= 18) && (! $second || $top['score'] >= ($second['score'] * 1.45));
        $selected = $auto ? (int) $top['category_id'] : null;

        return [
            'auto_select' => $auto,
            'selected_category_id' => $selected,
            'suggestions' => $suggestions->take($limit)->values()->all(),
            'marketplace_mappings' => $selected ? $this->marketplaceMappingsForCategory($selected) : null,
            'diagnostics' => (bool) config('app.debug') ? ['terms' => $terms, 'matched_parts_count' => $matches->count()] : null,
        ];
    }

    /** @return array{category_id: int|null, confidence: int|null, reason: string|null, auto_fill: bool} */
    public function suggestionForInput(array $data, ?int $ignorePartId = null): array
    {
        $result = $this->suggestCategoryFromTitle((string) ($data['name'] ?? ''), $ignorePartId);
        $top = $result['suggestions'][0] ?? null;
        return ['category_id' => $result['selected_category_id'] ?? ($top['category_id'] ?? null), 'confidence' => $top ? min(100, (int) round($top['score'] * 5)) : null, 'reason' => $top ? 'Sugestia z podobnych części: '.$top['matched_parts_count'].' dopasowań.' : ($result['diagnostics']['reason'] ?? null), 'auto_fill' => (bool) $result['auto_select']];
    }

    public function suggest(Part $part): Part { $s = $this->suggestionForInput($part->only(['name']), $part->exists ? $part->id : null); if ($s['category_id']) { $part->suggested_category_id=$s['category_id']; $part->category_confidence=$s['confidence']; $part->category_suggestion_reason=$s['reason']; $part->category_needs_review=!$s['auto_fill']; if (!$part->category_id && $s['auto_fill']) $part->category_id=$s['category_id']; } return $part; }

    /** @return array<string, mixed> */
    public function marketplaceMappingsForCategory(int $categoryId): array
    {
        $aliases = ['allegro' => ['allegro', 'allegro_main'], 'ovoko' => ['ovoko'], 'ebay' => ['ebay', 'ebay_de']];
        $labels = ['allegro' => 'Allegro', 'ovoko' => 'Ovoko', 'ebay' => 'eBay'];
        $mappings = MarketplaceCategoryMapping::query()->where('local_category_id', $categoryId)->whereIn('channel', array_merge(...array_values($aliases)))->get()->keyBy('channel');

        return collect($aliases)->mapWithKeys(function (array $channels, string $key) use ($labels, $mappings): array {
            $mapping = collect($channels)->map(fn (string $channel) => $mappings->get($channel))->filter()->first();

            return [$key => $mapping ? ['label'=>$labels[$key], 'status'=>'mapped', 'external_category_id'=>$mapping->external_category_id, 'external_category_name'=>$mapping->external_category_name, 'external_category_path'=>$mapping->external_category_path] : ['label'=>$labels[$key], 'status'=>'missing']];
        })->all();
    }

    /** @return array<string, float> */
    private function expandedTerms(string $title): array
    {
        $normalized = $this->normalize($title);
        $stop = ['volkswagen','audi','bmw','seat','skoda','tiguan','sprawny','nowa','nowy','oryginal','oryginalny','kompletny'];
        $terms = [];
        foreach (['kompletny dpf'=>7,'dpf'=>9,'filtr czastek stalych'=>9,'katalizator'=>7,'chlodnica zawor egr'=>10,'chlodnica egr'=>10,'chlodnica spalin egr'=>10,'zawor egr'=>7,'egr'=>5,'alternator'=>8,'rozrusznik'=>8] as $phrase=>$weight) if (str_contains($normalized, $phrase)) $terms[$phrase]=$weight;
        if (str_contains($normalized, 'dpf')) $terms += ['filtr czastek stalych'=>9, 'katalizator'=>5];
        if (str_contains($normalized, 'chlodnica') && str_contains($normalized, 'egr')) $terms += ['chlodnica egr'=>10, 'chlodnica spalin egr'=>10, 'zawor egr'=>6];
        foreach (preg_split('/\s+/', $normalized) ?: [] as $token) {
            if (strlen($token) < 3 || in_array($token, $stop, true) || preg_match('/^(\d{4}|\d+[.,]?\d*|[a-z0-9]{8,})$/i', $token)) continue;
            $terms[$token] ??= 2;
        }
        arsort($terms);
        return array_slice($terms, 0, 12, true);
    }

    private function normalize(string $value): string { $value = Str::ascii(Str::lower($value)); return preg_replace('/[^a-z0-9]+/', ' ', $value) ?: ''; }
    private function categoryPath(PartCategory $category): string { return $category->category_path ?: $category->full_slug_path ?: $category->name; }
    private function emptyResponse(string $reason): array { return ['auto_select'=>false,'selected_category_id'=>null,'suggestions'=>[],'marketplace_mappings'=>null,'diagnostics'=>(bool) config('app.debug')?['reason'=>$reason]:null]; }
}

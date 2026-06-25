<?php

namespace App\Services;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCategorySuggestionService
{
    /** @return array<string, mixed> */
    public function suggestCategoryFromTitle(string $title, ?int $ignorePartId = null, int $limit = 3): array
    {
        $limit = max(1, min(5, $limit));
        $analysis = $this->analyzeTitle($title);

        if ($analysis['important_tokens'] === []) {
            return $this->emptyResponse('Brak wystarczających znaczących tokenów części w tytule.', $analysis);
        }

        $candidates = $this->candidateParts($analysis, $ignorePartId);
        if ($candidates->isEmpty()) {
            return $this->emptyResponse('Brak podobnych części z finalną kategorią.', $analysis);
        }

        $documentFrequency = $this->documentFrequency($candidates);
        $matchedParts = [];
        $categories = [];

        foreach ($candidates as $part) {
            if (! $part->category) {
                continue;
            }

            $partAnalysis = $this->analyzeTitle(implode(' ', array_filter([
                $part->name,
                $part->sku,
                $part->part_number,
                $part->oem_number,
                $part->manufacturer_code,
            ])));

            $scoreData = $this->scorePart($analysis, $partAnalysis, $documentFrequency, max(1, $candidates->count()));
            if ($scoreData['score'] < 3.5 || $scoreData['important_overlap'] < 1) {
                continue;
            }

            $categoryId = (int) $part->category_id;
            $matchedParts[] = [
                'id' => $part->id,
                'title' => $part->name,
                'category_id' => $categoryId,
                'score' => round($scoreData['score'], 2),
                'matched_terms' => $scoreData['matched_terms'],
                'matched_ngrams' => $scoreData['matched_ngrams'],
            ];

            $categories[$categoryId] ??= [
                'category' => $part->category,
                'score' => 0.0,
                'parts' => [],
                'matched_terms' => [],
            ];
            $categories[$categoryId]['score'] += $scoreData['score'];
            $categories[$categoryId]['parts'][$part->id] = ['id' => $part->id, 'title' => $part->name, 'score' => round($scoreData['score'], 2)];
            $categories[$categoryId]['matched_terms'] = array_values(array_unique(array_merge($categories[$categoryId]['matched_terms'], $scoreData['matched_terms'], $scoreData['matched_ngrams'])));
        }

        $suggestions = collect($categories)->map(function (array $row, int $categoryId): array {
            $category = $row['category'];
            $partCount = count($row['parts']);
            $consistencyBoost = $partCount > 1 ? min(8, $partCount * 2) : 0;

            return [
                'category_id' => $categoryId,
                'category_name' => $category->name,
                'category_path' => $this->categoryPath($category),
                'score' => round($row['score'] + $consistencyBoost, 2),
                'matched_terms' => array_values(array_slice($row['matched_terms'], 0, 10)),
                'matched_parts_count' => $partCount,
                'matched_parts' => array_values(array_slice($row['parts'], 0, 5, true)),
            ];
        })->sortByDesc('score')->values();

        if ($suggestions->isEmpty()) {
            return $this->emptyResponse('Brak punktowanych dopasowań kategorii.', $analysis, $matchedParts);
        }

        $top = $suggestions->first();
        $second = $suggestions->get(1);
        $confidence = $this->confidence($top, $second);
        $auto = $confidence >= 78 && $top['matched_parts_count'] >= 2 && (! $second || $top['score'] >= ($second['score'] * 1.55));
        $selected = $auto ? (int) $top['category_id'] : null;
        $reason = $auto ? 'Jedna kategoria ma wyraźną przewagę podobnych produktów.' : 'Brak wyraźnego zwycięzcy; pokazano propozycje.';

        return [
            'auto_select' => $auto,
            'selected_category_id' => $selected,
            'suggestions' => $suggestions->take($limit)->values()->all(),
            'marketplace_mappings' => $selected ? $this->marketplaceMappingsForCategory($selected) : null,
            'diagnostics' => $this->diagnostics($analysis, $matchedParts, $suggestions->all(), $reason, $confidence),
        ];
    }

    /** @return array{category_id: int|null, confidence: int|null, reason: string|null, auto_fill: bool} */
    public function suggestionForInput(array $data, ?int $ignorePartId = null): array
    {
        $result = $this->suggestCategoryFromTitle((string) ($data['name'] ?? ''), $ignorePartId);
        $top = $result['suggestions'][0] ?? null;
        return ['category_id' => $result['selected_category_id'] ?? ($top['category_id'] ?? null), 'confidence' => $result['diagnostics']['confidence'] ?? null, 'reason' => $top ? 'Sugestia z podobnych części: '.$top['matched_parts_count'].' dopasowań.' : ($result['diagnostics']['auto_select_reason'] ?? null), 'auto_fill' => (bool) $result['auto_select']];
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

    /** @return array<string, mixed> */
    private function analyzeTitle(string $title): array
    {
        $normalized = $this->normalize($title);
        $tokens = array_values(array_filter(preg_split('/\s+/', $normalized) ?: []));
        $noise = [];
        $important = [];
        $oem = [];
        foreach ($tokens as $token) {
            $type = $this->noiseType($token);
            if ($type) { $noise[] = $token; if ($type === 'oem') $oem[] = $token; continue; }
            if (strlen($token) >= 2) $important[] = $token;
        }
        $candidateTerms = $this->candidateTerms($important, false);
        $searchPhrases = array_values(array_unique(array_merge($candidateTerms, $oem)));
        return ['normalized_title'=>$normalized, 'tokens'=>$tokens, 'important_tokens'=>$important, 'noise_tokens_removed'=>array_values(array_unique($noise)), 'oem_tokens'=>$oem, 'candidate_terms'=>$candidateTerms, 'search_phrases'=>$searchPhrases];
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii(Str::lower($value));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '');
    }

    private function noiseType(string $token): ?string
    {
        $brandsModels = ['audi','volkswagen','vw','bmw','mercedes','opel','seat','skoda','ford','renault','peugeot','citroen','toyota','honda','nissan','mazda','volvo','fiat','hyundai','kia','a4','s4','a3','a5','a6','golf','passat','tiguan','octavia','leon'];
        $state = ['sprawny','sprawna','nowy','nowa','uzywany','uzywana','oryginal','oryginalny','oryginalna','kompletny','kompletna','komplet'];
        if (in_array($token, $brandsModels, true) || in_array($token, $state, true)) return 'low_weight';
        if (preg_match('/^(19|20)\d{2}$/', $token)) return 'year';
        if (preg_match('/^\d+[.,]?\d*$/', $token)) return 'capacity_or_number';
        if (preg_match('/^[a-z0-9]{8,}$/', $token)) return 'oem';
        if (preg_match('/^(b\d|[0-9][a-z]|[a-z][0-9])$/', $token)) return 'generation';
        return null;
    }

    /** @return array<int, string> */
    private function candidateTerms(array $tokens, bool $withSingles = true): array
    {
        $terms = [];
        $count = count($tokens);
        if ($count) $terms[] = implode(' ', $tokens);
        for ($n = min(5, $count); $n >= 2; $n--) {
            for ($i = 0; $i <= $count - $n; $i++) $terms[] = implode(' ', array_slice($tokens, $i, $n));
        }
        if ($withSingles) foreach ($tokens as $token) if (strlen($token) >= 3) $terms[] = $token;
        return array_values(array_unique($terms));
    }

    private function candidateParts(array $analysis, ?int $ignorePartId): Collection
    {
        $phrases = array_slice($analysis['search_phrases'], 0, 18);
        return Part::query()->with('category:id,name,parent_id,category_path,full_slug_path')
            ->select(['id','category_id','name','sku','part_number','oem_number','manufacturer_code'])
            ->whereNotNull('category_id')
            ->when($ignorePartId, fn ($query) => $query->whereKeyNot($ignorePartId))
            ->where(function ($query) use ($phrases): void {
                foreach ($phrases as $phrase) {
                    if (strlen($phrase) < 3) continue;
                    $like = '%'.$phrase.'%';
                    $query->orWhere('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('part_number', 'like', $like)->orWhere('oem_number', 'like', $like)->orWhere('manufacturer_code', 'like', $like);
                }
            })->latest('id')->limit(120)->get();
    }

    private function documentFrequency(Collection $parts): array
    {
        $df = [];
        foreach ($parts as $part) foreach (array_unique($this->analyzeTitle($part->name)['important_tokens']) as $token) $df[$token] = ($df[$token] ?? 0) + 1;
        return $df;
    }

    private function scorePart(array $input, array $part, array $df, int $docs): array
    {
        $inputTokens = array_unique($input['important_tokens']);
        $partTokens = array_unique($part['important_tokens']);
        $overlap = array_values(array_intersect($inputTokens, $partTokens));
        $score = 0.0;
        foreach ($overlap as $token) $score += 2.5 * (1 + log(($docs + 1) / (($df[$token] ?? 0) + 1)));
        $inputTerms = $this->candidateTerms($input['important_tokens'], false);
        $partPhrase = ' '.implode(' ', $part['important_tokens']).' ';
        $ngrams = [];
        foreach ($inputTerms as $term) if (substr_count($term, ' ') >= 1 && str_contains($partPhrase, ' '.$term.' ')) { $ngrams[] = $term; $score += 4 + substr_count($term, ' '); }
        $oemOverlap = array_intersect($input['oem_tokens'], $part['oem_tokens']);
        if ($oemOverlap && $overlap) $score += 2;
        return ['score'=>$score, 'important_overlap'=>count($overlap), 'matched_terms'=>$overlap, 'matched_ngrams'=>array_slice($ngrams, 0, 5)];
    }

    private function confidence(array $top, ?array $second): int
    {
        $base = min(90, (int) round($top['score'] * 4));
        if ($second) $base = min($base, (int) round(55 + min(35, (($top['score'] - $second['score']) / max(1, $top['score'])) * 100)));
        return max(0, min(100, $base));
    }

    private function diagnostics(array $analysis, array $matchedParts, array $matchedCategories, string $reason, int $confidence): array
    {
        return ['normalized_title'=>$analysis['normalized_title'], 'noise_tokens_removed'=>$analysis['noise_tokens_removed'], 'candidate_terms'=>$analysis['candidate_terms'], 'search_phrases'=>$analysis['search_phrases'], 'matched_parts'=>$matchedParts, 'matched_categories'=>$matchedCategories, 'auto_select_reason'=>$reason, 'confidence'=>$confidence];
    }

    private function categoryPath(PartCategory $category): string { return $category->category_path ?: $category->full_slug_path ?: $category->name; }
    private function emptyResponse(string $reason, array $analysis = [], array $matchedParts = []): array { return ['auto_select'=>false,'selected_category_id'=>null,'suggestions'=>[],'marketplace_mappings'=>null,'diagnostics'=>$this->diagnostics($analysis + ['normalized_title'=>'','noise_tokens_removed'=>[],'candidate_terms'=>[],'search_phrases'=>[]], $matchedParts, [], $reason, 0)]; }
}

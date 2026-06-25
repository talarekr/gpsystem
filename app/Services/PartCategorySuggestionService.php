<?php

namespace App\Services;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCategorySuggestionService
{
    private const DEFAULT_MIN_SCORE = 3.5;

    /** @return array<string, mixed> */
    public function suggestCategoryFromTitle(string $title, ?int $ignorePartId = null, int $limit = 3, ?float $minScore = null, bool $includeRejected = false): array
    {
        $limit = max(1, min(50, $limit));
        $minScore ??= self::DEFAULT_MIN_SCORE;
        $analysis = $this->analyzeTitle($title);
        $thresholds = ['min_part_score' => $minScore, 'min_important_overlap' => 1, 'auto_select_confidence' => 78, 'auto_select_min_parts' => 2, 'auto_select_score_ratio' => 1.55];

        if ($analysis['important_tokens'] === []) {
            return $this->emptyResponse('Brak wystarczających znaczących tokenów części w tytule.', $analysis, [], [], [], $thresholds);
        }

        $candidates = $this->candidateParts($analysis, $ignorePartId, max(120, $limit * 12));
        if ($candidates->isEmpty()) {
            return $this->emptyResponse('Brak podobnych części z finalną kategorią.', $analysis, [], [], [], $thresholds);
        }

        $documentFrequency = $this->documentFrequency($candidates);
        $matchedParts = [];
        $rejectedParts = [];
        $rejectionReasons = [];
        $rawCandidateParts = [];
        $categories = [];

        foreach ($candidates as $part) {
            $rawCandidateParts[] = $this->partDiagnosticPayload($part, ['score' => 0, 'matched_terms' => [], 'matched_ngrams' => []], 'Surowy kandydat znaleziony po frazie lub tokenie wyszukiwania.');

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
            $included = $scoreData['score'] >= $minScore && $scoreData['important_overlap'] >= 1;
            $why = $included
                ? $this->includedReason($scoreData)
                : $this->rejectedReason($scoreData, $minScore);

            if (! $included) {
                $payload = $this->partDiagnosticPayload($part, $scoreData, $why);
                $rejectedParts[] = $payload;
                $rejectionReasons[$why] = ($rejectionReasons[$why] ?? 0) + 1;
                continue;
            }

            $categoryId = (int) $part->category_id;
            $matchedParts[] = $this->partDiagnosticPayload($part, $scoreData, $why);

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
            return $this->emptyResponse('Brak punktowanych dopasowań kategorii.', $analysis, $rawCandidateParts, $matchedParts, $includeRejected ? $rejectedParts : [], $thresholds, $rejectionReasons);
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
            'diagnostics' => $this->diagnostics($analysis, $rawCandidateParts, $matchedParts, $suggestions->all(), $includeRejected ? $rejectedParts : [], $rejectionReasons, $thresholds, $reason, $confidence, $auto, $suggestions->take($limit)->values()->all()),
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
        $searchPhrases = array_values(array_unique(array_merge($candidateTerms, $this->localizedPhrases($candidateTerms), $oem)));
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
        if (preg_match('/^\d+(?:[a-z]{1,4})?$/', $token) || preg_match('/^\d+[.,]?\d*[a-z]{0,4}$/', $token)) return 'capacity_or_number';
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
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) $terms[] = $tokens[$i].' '.$tokens[$j];
        }
        if ($withSingles) foreach ($tokens as $token) if (strlen($token) >= 3) $terms[] = $token;
        return array_values(array_unique($terms));
    }

    private function candidateParts(array $analysis, ?int $ignorePartId, int $limit = 120): Collection
    {
        $phrases = array_slice($analysis['search_phrases'], 0, 40);
        $tokens = array_slice($analysis['important_tokens'], 0, 8);

        return Part::query()->with('category:id,name,parent_id,category_path,full_slug_path')
            ->select(['id','category_id','name','sku','part_number','oem_number','manufacturer_code'])
            ->whereNotNull('category_id')
            ->when($ignorePartId, fn ($query) => $query->whereKeyNot($ignorePartId))
            ->where(function ($query) use ($phrases, $tokens): void {
                foreach ($phrases as $phrase) {
                    if (strlen($phrase) < 3) continue;
                    $like = '%'.$phrase.'%';
                    $query->orWhere('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('part_number', 'like', $like)->orWhere('oem_number', 'like', $like)->orWhere('manufacturer_code', 'like', $like);
                }
                foreach ($tokens as $token) {
                    if (strlen($token) < 3) continue;
                    foreach ($this->localizedPhrases([$token]) as $variant) {
                        $query->orWhere('name', 'like', '%'.$variant.'%');
                    }
                }
            })->latest('id')->limit($limit)->get();
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
        foreach ($inputTerms as $term) {
            if (substr_count($term, ' ') >= 1 && str_contains($partPhrase, ' '.$term.' ')) {
                $ngrams[] = $term;
                $score += substr_count($term, ' ') >= 2 ? 8 : 7;
            }
        }
        $oemOverlap = array_intersect($input['oem_tokens'], $part['oem_tokens']);
        if ($oemOverlap && $overlap) $score += 2;
        return ['score'=>$score, 'important_overlap'=>count($overlap), 'matched_terms'=>$overlap, 'matched_ngrams'=>array_slice(array_values(array_unique($ngrams)), 0, 8)];
    }

    private function confidence(array $top, ?array $second): int
    {
        $base = min(90, (int) round($top['score'] * 4));
        if ($second) $base = min($base, (int) round(55 + min(35, (($top['score'] - $second['score']) / max(1, $top['score'])) * 100)));
        return max(0, min(100, $base));
    }

    private function diagnostics(array $analysis, array $rawCandidateParts, array $matchedParts, array $matchedCategories, array $rejectedParts, array $rejectionReasons, array $thresholds, string $reason, int $confidence, bool $autoSelect = false, array $suggestions = []): array
    {
        return [
            'ok' => true,
            'read_only' => true,
            'normalized_title'=>$analysis['normalized_title'],
            'noise_tokens_removed'=>$analysis['noise_tokens_removed'],
            'candidate_terms'=>$analysis['candidate_terms'],
            'search_phrases'=>$analysis['search_phrases'],
            'raw_candidate_parts'=>$rawCandidateParts,
            'matched_parts'=>$matchedParts,
            'matched_categories'=>$matchedCategories,
            'rejected_parts'=>$rejectedParts,
            'rejection_reasons'=>$rejectionReasons,
            'thresholds'=>$thresholds,
            'auto_select'=>$autoSelect,
            'auto_select_reason'=>$reason,
            'confidence'=>$confidence,
            'suggestions'=>$suggestions,
        ];
    }

    private function localizedPhrases(array $phrases): array
    {
        $map = ['waz' => 'wąż', 'przewod' => 'przewód', 'chlodnicy' => 'chłodnicy', 'chlodnica' => 'chłodnica', 'czastek' => 'cząstek', 'stalych' => 'stałych', 'podnosnik' => 'podnośnik'];
        return array_values(array_unique(array_filter(array_map(function (string $phrase) use ($map): string {
            $tokens = explode(' ', $phrase);
            return implode(' ', array_map(fn (string $token): string => $map[$token] ?? $token, $tokens));
        }, $phrases), fn (string $phrase): bool => $phrase !== '')));
    }

    private function partDiagnosticPayload(Part $part, array $scoreData, string $why): array
    {
        $category = $part->category;
        return [
            'part_id' => $part->id,
            'title' => $part->name,
            'name' => $part->name,
            'sku' => $part->sku,
            'main_code' => $part->part_number ?: $part->oem_number ?: $part->manufacturer_code,
            'category_id' => $part->category_id,
            'category_name' => $category?->name,
            'category_path' => $category ? $this->categoryPath($category) : null,
            'score' => round((float) ($scoreData['score'] ?? 0), 2),
            'matched_tokens' => $scoreData['matched_terms'] ?? [],
            'matched_phrases' => $scoreData['matched_ngrams'] ?? [],
            'matched_terms' => $scoreData['matched_terms'] ?? [],
            'matched_ngrams' => $scoreData['matched_ngrams'] ?? [],
            'why_included_or_rejected' => $why,
        ];
    }

    private function includedReason(array $scoreData): string
    {
        if (($scoreData['matched_ngrams'] ?? []) !== []) return 'Uwzględniono: dopasowana mocna fraza rzeczowa '.implode(', ', $scoreData['matched_ngrams']).'.';
        return 'Uwzględniono: wystarczające pokrycie tokenów nazwy części.';
    }

    private function rejectedReason(array $scoreData, float $minScore): string
    {
        if (($scoreData['important_overlap'] ?? 0) < 1) return 'Odrzucono: brak wspólnych istotnych tokenów nazwy części.';
        return 'Odrzucono: wynik '.round((float) ($scoreData['score'] ?? 0), 2).' poniżej progu '.$minScore.'.';
    }

    private function categoryPath(PartCategory $category): string { return $category->category_path ?: $category->full_slug_path ?: $category->name; }
    private function emptyResponse(string $reason, array $analysis = [], array $rawCandidateParts = [], array $matchedParts = [], array $rejectedParts = [], array $thresholds = [], array $rejectionReasons = []): array { return ['auto_select'=>false,'selected_category_id'=>null,'suggestions'=>[],'marketplace_mappings'=>null,'diagnostics'=>$this->diagnostics($analysis + ['normalized_title'=>'','noise_tokens_removed'=>[],'candidate_terms'=>[],'search_phrases'=>[]], $rawCandidateParts, $matchedParts, [], $rejectedParts, $rejectionReasons, $thresholds, $reason, 0, false, [])]; }
}

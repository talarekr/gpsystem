<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\PartCategorySuggestionService;
use Illuminate\Http\Request;

class AuditPartCategorySuggestionsController extends Controller
{
    public function __invoke(Request $request, PartCategorySuggestionService $service)
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $scope = in_array($request->query('scope', 'with_category'), ['all', 'to_publish', 'with_category'], true) ? (string) $request->query('scope', 'with_category') : 'with_category';
        $limit = max(1, min(1000, $request->integer('limit', 200)));
        $offset = max(0, $request->integer('offset', 0));
        $sampleLimit = max(0, min(200, $request->integer('sample_limit', 50)));
        $includeDebug = $request->boolean('include_debug');
        $onlyMismatches = $request->boolean('only_mismatches');
        $categoryId = $request->integer('category_id') ?: null;
        $minConfidence = is_numeric($request->query('min_confidence')) ? (float) $request->query('min_confidence') : null;

        $query = Part::query()->with('category:id,name,parent_id,category_path,full_slug_path')->select(['id','name','category_id','needs_listing','status']);
        if ($scope === 'with_category') $query->whereNotNull('category_id');
        if ($scope === 'to_publish') $query->where('needs_listing', true);
        if ($categoryId) $query->where('category_id', $categoryId);

        $parts = $query->orderBy('id')->offset($offset)->limit($limit)->get();
        $counters = array_fill_keys([
            'total_checked','with_title_count','without_title_count','with_expected_category_count','without_expected_category_count','auto_select_count','auto_select_correct_count','auto_select_wrong_count','top1_suggestion_count','top1_correct_count','top1_wrong_count','top3_contains_expected_count','no_suggestion_count','low_confidence_count','mismatch_count'
        ], 0);
        $items = [];
        $perCategory = [];
        $confusedPairs = [];
        $noSuggestionCategories = [];
        $noiseTokens = [];

        foreach ($parts as $part) {
            $counters['total_checked']++;
            $title = trim((string) $part->name);
            $expectedId = $part->category_id ? (int) $part->category_id : null;
            $expectedPath = $part->category ? $this->categoryPath($part->category) : null;
            $title === '' ? $counters['without_title_count']++ : $counters['with_title_count']++;
            $expectedId ? $counters['with_expected_category_count']++ : $counters['without_expected_category_count']++;

            $result = $title === '' ? ['suggestions' => [], 'auto_select' => false, 'selected_category_id' => null, 'diagnostics' => ['confidence' => 0, 'noise_tokens_removed' => []]] : $service->suggestCategoryFromTitle($title, $part->id, 3, includeRejected: false);
            $suggestions = $result['suggestions'] ?? [];
            $top = $suggestions[0] ?? null;
            $topId = $top ? (int) $top['category_id'] : null;
            $top3 = collect($suggestions)->take(3)->pluck('category_id')->map(fn ($id) => (int) $id)->contains($expectedId);
            $confidence = (int) ($result['diagnostics']['confidence'] ?? 0);
            $auto = (bool) ($result['auto_select'] ?? false);
            $selectedId = $result['selected_category_id'] ?? null;

            if ($auto) $counters['auto_select_count']++;
            if ($auto && $selectedId === $expectedId) $counters['auto_select_correct_count']++;
            if ($auto && $selectedId !== $expectedId) $counters['auto_select_wrong_count']++;
            if ($top) $counters['top1_suggestion_count']++;
            if ($top && $topId === $expectedId) $counters['top1_correct_count']++;
            if ($top && $topId !== $expectedId) $counters['top1_wrong_count']++;
            if ($top3) $counters['top3_contains_expected_count']++;
            if (! $top) $counters['no_suggestion_count']++;
            if ($minConfidence !== null && $confidence < $minConfidence) $counters['low_confidence_count']++;

            $status = ! $top ? 'no_suggestion' : (($minConfidence !== null && $confidence < $minConfidence) ? 'low_confidence' : ($topId === $expectedId ? 'correct' : 'wrong'));
            if ($status !== 'correct') $counters['mismatch_count']++;
            foreach (($result['diagnostics']['noise_tokens_removed'] ?? []) as $token) $noiseTokens[$token] = ($noiseTokens[$token] ?? 0) + 1;

            if ($expectedId) {
                $perCategory[$expectedId] ??= ['category_id'=>$expectedId,'category_path'=>$expectedPath,'total'=>0,'top1_correct'=>0,'top3_contains_expected'=>0,'no_suggestion'=>0];
                $perCategory[$expectedId]['total']++;
                if ($topId === $expectedId) $perCategory[$expectedId]['top1_correct']++;
                if ($top3) $perCategory[$expectedId]['top3_contains_expected']++;
                if (! $top) { $perCategory[$expectedId]['no_suggestion']++; $noSuggestionCategories[$expectedId] = ($noSuggestionCategories[$expectedId] ?? 0) + 1; }
            }
            if ($expectedId && $topId && $topId !== $expectedId) {
                $key = $expectedId.'->'.$topId;
                $confusedPairs[$key] ??= ['expected_category_id'=>$expectedId,'expected_category_path'=>$expectedPath,'suggested_category_id'=>$topId,'suggested_category_path'=>$top['category_path'] ?? null,'count'=>0];
                $confusedPairs[$key]['count']++;
            }

            if ((! $onlyMismatches || $status !== 'correct') && count($items) < $sampleLimit) {
                $item = ['part_id'=>$part->id,'title'=>$title,'expected_category_id'=>$expectedId,'expected_category_path'=>$expectedPath,'auto_select'=>$auto,'selected_category_id'=>$selectedId,'selected_category_path'=>$this->pathForId($selectedId),'top_suggestions'=>$suggestions,'top3_contains_expected'=>$top3,'confidence'=>$confidence,'status'=>$status];
                if ($includeDebug) $item['debug'] = collect($result['diagnostics'] ?? [])->only(['normalized_title','noise_tokens_removed','candidate_terms','search_phrases','matched_categories','matched_parts'])->all();
                $items[] = $item;
            }
        }

        $den = max(1, $counters['with_expected_category_count']);
        $payload = $counters + [
            'ok'=>true,'read_only'=>true,
            'accuracy_top1_percent'=>round($counters['top1_correct_count'] / $den * 100, 2),
            'accuracy_top3_percent'=>round($counters['top3_contains_expected_count'] / $den * 100, 2),
            'auto_select_accuracy_percent'=>round($counters['auto_select_correct_count'] / max(1, $counters['auto_select_count']) * 100, 2),
            'items'=>$items,
            'per_category'=>array_values($perCategory),
            'confused_pairs'=>array_values($confusedPairs),
            'no_suggestion_categories'=>$noSuggestionCategories,
            'noise_tokens_removed_frequency'=>$noiseTokens,
            'parts_changed'=>false,'products_changed'=>false,'offers_changed'=>false,'mappings_changed'=>false,'allegro_write'=>false,'ovoko_write'=>false,'ebay_write'=>false,
        ];

        return response()->json($payload);
    }

    private function pathForId($id): ?string
    {
        $category = $id ? PartCategory::query()->find($id) : null;
        return $category ? $this->categoryPath($category) : null;
    }

    private function categoryPath(PartCategory $category): string
    {
        return $category->category_path ?: $category->full_slug_path ?: $category->name;
    }
}

<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\PartCategorySuggestionService;
use Illuminate\Http\Request;

class DebugPartCategorySuggestionController extends Controller
{
    public function __invoke(Request $request, PartCategorySuggestionService $service)
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $title = trim((string) $request->query('title', ''));
        $partId = $request->integer('part_id') ?: null;
        if ($title === '' && $partId) {
            $title = (string) Part::query()->whereKey($partId)->value('name');
        }
        if ($title === '') {
            return response()->json(['ok' => false, 'read_only' => true, 'error_message' => 'Missing title or part_id.'], 422);
        }

        $limit = max(1, min(50, $request->integer('limit', 50)));
        $minScore = $request->query('min_score');
        $result = $service->suggestCategoryFromTitle(
            $title,
            $partId,
            $limit,
            is_numeric($minScore) ? (float) $minScore : null,
            $request->boolean('include_rejected')
        );

        return response()->json(array_merge($result['diagnostics'] ?? [], [
            'ok' => true,
            'read_only' => true,
            'part_id' => $partId,
            'title' => $title,
            'selected_category_id' => $result['selected_category_id'] ?? null,
            'marketplace_mappings' => $result['marketplace_mappings'] ?? null,
            'parts_changed' => false,
            'products_changed' => false,
            'offers_changed' => false,
            'mappings_changed' => false,
            'allegro_write' => false,
            'ovoko_write' => false,
            'ebay_write' => false,
        ]));
    }
}

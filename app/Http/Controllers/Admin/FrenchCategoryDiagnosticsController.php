<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Storefront\FrenchCategoryDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class FrenchCategoryDiagnosticsController extends Controller
{
    public function __invoke(Request $request, FrenchCategoryDiagnostics $diagnostics)
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'missing_only' => ['nullable', 'boolean'], 'format' => ['nullable', 'in:html,csv']]);
        $q = $input['q'] ?? '';
        $report = $diagnostics->report($q, (bool) ($input['missing_only'] ?? false));
        if (($input['format'] ?? '') === 'csv') {
            return response()->streamDownload(function () use ($report): void {
                $stream = fopen('php://output', 'w');
                fputcsv($stream, ['id', 'parent_id', 'level', 'source_system', 'is_visible', 'name', 'full_slug_path', 'fr_name', 'fr_status', 'reason',
                    'public_name', 'description_ready', 'in_public_fr_tree', 'resolved_public_id', 'same_path_count', 'same_as_source_name', 'fr_slug', 'fr_slug_reason', 'fr_public_path', 'fr_public_url', 'fr_path_has_fallback', 'slug_history_count', 'unresolved_ancestor_segments'], ',', '"', '');
                foreach ($report['rows'] as $row) {
                    $category = $row['category'];
                    $values = [$category->id, $category->parent_id, $row['level'], $category->source_system, (int) $category->is_visible,
                        $category->name, $category->full_slug_path, $row['translation']?->name, $row['translation']?->status, $row['reason'],
                        $row['public_name'], (int) $row['description_ready'], (int) $row['in_public_fr_tree'], $row['resolved_public_id'], $row['same_path_count'], (int) $row['same_as_source_name'], $row['fr_slug'], $row['fr_slug_reason'], $row['fr_public_path'], $row['fr_public_url'], (int) $row['fr_path_has_fallback'], $row['slug_history_count'], $row['unresolved_ancestor_segments']];
                    fputcsv($stream, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@-]/', $v) ? "'".$v : $v, $values), ',', '"', '');
                }
                fclose($stream);
            }, 'gpswiss-fr-category-diagnostics.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        $page = LengthAwarePaginator::resolveCurrentPage();
        $categories = new LengthAwarePaginator($report['rows']->forPage($page, 100)->values(), $report['rows']->count(), 100, $page,
            ['path' => url()->current(), 'query' => $request->query()]);
        $rows = $categories->getCollection();
        $schemaReady = $report['schema_ready'];
        $summary = $report['summary'];

        return response()->view('admin.tools.storefront.fr-category-diagnostics', compact('categories', 'rows', 'schemaReady', 'q', 'summary'))
            ->header('Cache-Control', 'private, no-store');
    }
}

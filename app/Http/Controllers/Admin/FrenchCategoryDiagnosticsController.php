<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Current database fields only: no translation provider, cache mutation or model save. */
class FrenchCategoryDiagnosticsController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = $request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? '';
        $schemaReady = Schema::hasTable('category_translations');
        $categories = PartCategory::query()->when($schemaReady, fn ($query) => $query->with('translations'))
            ->when($q !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('full_slug_path', 'like', '%'.$q.'%')))
            ->orderBy('full_slug_path')->orderBy('id')->paginate(100)->withQueryString();
        $rows = $categories->getCollection()->map(function (PartCategory $category) use ($schemaReady): array {
            $fr = $schemaReady ? $category->translationForLocale('fr') : null;
            $reason = match (true) {
                ! $schemaReady => 'missing_translation_table',
                ! $fr => 'missing_translation',
                ! $fr->isReady() => 'nonpublic_status',
                trim((string) $fr->name) === '' => 'empty_fr_name',
                default => 'ready_fr_name',
            };

            return ['category' => $category, 'translation' => $fr, 'reason' => $reason,
                'public_name' => $category->storefrontPublicNameForLocale('fr'),
                'description_ready' => $fr?->isReady() && trim((string) $fr?->description) !== ''];
        });

        return response()->view('admin.tools.storefront.fr-category-diagnostics', compact('categories', 'rows', 'schemaReady', 'q'))
            ->header('Cache-Control', 'private, no-store');
    }
}

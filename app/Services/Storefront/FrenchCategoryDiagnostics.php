<?php

namespace App\Services\Storefront;

use App\Models\PartCategory;
use Illuminate\Support\Facades\Schema;

/** No language guessing, translation calls, cache invalidation or model saves. */
class FrenchCategoryDiagnostics
{
    public function report(string $q = '', bool $missingOnly = false): array
    {
        $schemaReady = Schema::hasTable('category_translations');
        $all = PartCategory::query()->when($schemaReady, fn ($query) => $query->with('translations'))
            ->orderBy('full_slug_path')->orderBy('id')->get()->keyBy('id');
        $tree = app(CategoryTreeService::class)->forLocale('fr');
        $public = $tree->all();
        $paths = $public->groupBy(fn ($category) => trim((string) ($category->full_slug_path ?: $category->slug), '/'));
        $rows = $all->map(function (PartCategory $category) use ($all, $public, $paths, $tree, $schemaReady): array {
            $fr = $schemaReady ? $category->translationForLocale('fr') : null;
            $reason = match (true) {
                ! $schemaReady => 'missing_translation_table',
                ! $fr => 'missing_translation',
                ! $fr->isReady() => 'nonpublic_status',
                trim((string) $fr->name) === '' => 'empty_fr_name',
                default => 'ready_fr_name',
            };
            $level = 0;
            $parent = $category;
            $seen = [];
            while ($parent->parent_id && $all->has($parent->parent_id) && ! isset($seen[$parent->id])) {
                $seen[$parent->id] = true;
                $level++;
                $parent = $all[$parent->parent_id];
            }
            $path = trim((string) ($category->full_slug_path ?: $category->slug), '/');
            $sameName = $fr?->isReady() && trim((string) $fr->name) !== ''
                && in_array(mb_strtolower(trim($fr->name)), [mb_strtolower(trim((string) $category->name)), mb_strtolower(trim($category->publicDisplayName()))], true);

            return ['category' => $category, 'translation' => $fr, 'reason' => $reason,
                'public_name' => $category->storefrontPublicNameForLocale('fr'), 'level' => $level,
                'in_public_fr_tree' => $public->has($category->id),
                'resolved_public_id' => $path !== '' ? $tree->findByPublicPath($path)?->id : null,
                'same_path_count' => $paths->get($path, collect())->count(), 'same_as_source_name' => $sameName,
                'description_ready' => $fr?->isReady() && trim((string) $fr?->description) !== ''];
        })->filter(fn ($row) => ($q === '' || mb_stripos((string) $row['category']->name, $q) !== false || mb_stripos((string) $row['category']->full_slug_path, $q) !== false)
            && (! $missingOnly || $row['reason'] !== 'ready_fr_name'))->values();
        $visible = $rows->where('in_public_fr_tree', true);

        return ['schema_ready' => $schemaReady, 'rows' => $rows, 'summary' => ['total_rows' => $rows->count(),
            'public_fr_rows' => $visible->count(), 'public_ready_names' => $visible->where('reason', 'ready_fr_name')->count(),
            'public_fallback_names' => $visible->where('reason', '!=', 'ready_fr_name')->count(),
            'public_ready_equal_to_source' => $visible->where('same_as_source_name', true)->count(),
            'fallback_by_reason' => $visible->where('reason', '!=', 'ready_fr_name')->countBy('reason')->all()]];
    }
}

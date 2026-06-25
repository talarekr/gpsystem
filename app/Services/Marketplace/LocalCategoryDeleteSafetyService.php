<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\PartCategory;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class LocalCategoryDeleteSafetyService
{
    public function inspect(int $categoryId): array
    {
        $category = PartCategory::query()->find($categoryId);

        if (! $category) {
            return [
                'exists' => false,
                'can_delete' => false,
                'blockers' => ['Kategoria lokalna nie istnieje.'],
                'counts' => ['products_count' => 0, 'children_count' => 0, 'descendants_products_count' => 0, 'mappings_count' => 0],
                'count_sources' => $this->countSources(),
                'samples' => ['product_ids' => [], 'blocking_products' => [], 'children' => []],
                'mappings' => ['has_marketplace_mapping' => false, 'has_ebay_mapping' => false, 'has_allegro_mapping' => false, 'has_ovoko_mapping' => false, 'items' => []],
            ];
        }

        $children = PartCategory::query()->where('parent_id', $category->id)->ordered()->limit(10)->get(['id', 'name', 'category_path', 'full_slug_path']);
        $childrenCount = PartCategory::query()->where('parent_id', $category->id)->count();
        $productsCount = DB::table('parts')->where('category_id', $category->id)->count();
        $blockingProducts = DB::table('parts')
            ->where('category_id', $category->id)
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'name', 'category_id'])
            ->map(fn (object $part): array => [
                'product_id' => (int) $part->id,
                'title' => (string) ($part->name ?? ''),
                'current_category_id' => $part->category_id === null ? null : (int) $part->category_id,
                'edit_url' => url('/admin/parts/'.((int) $part->id).'/edit'),
            ])
            ->values()
            ->all();
        $descendantIds = $this->descendantIds((int) $category->id);
        $descendantsProductsCount = $descendantIds === [] ? 0 : DB::table('parts')->whereIn('category_id', $descendantIds)->count();
        $mappings = MarketplaceCategoryMapping::query()->where('local_category_id', $category->id)->get();
        $mappingItems = $mappings->map(fn (MarketplaceCategoryMapping $mapping): array => [
            'id' => $mapping->id,
            'channel' => $mapping->channel,
            'external_category_id' => $mapping->external_category_id,
            'external_category_name' => $mapping->external_category_name,
            'external_category_path' => $mapping->external_category_path,
        ])->values()->all();

        $blockers = [];
        if ($productsCount > 0) $blockers[] = 'ma produkty';
        if ($childrenCount > 0) $blockers[] = 'ma dzieci';
        if ($descendantsProductsCount > 0) $blockers[] = 'ma potomków z produktami';
        if ($mappings->isNotEmpty()) $blockers[] = 'ma mappingi';

        return [
            'exists' => true,
            'can_delete' => $blockers === [],
            'blockers' => $blockers,
            'local_category' => [
                'id' => (string) $category->id,
                'name' => $category->name,
                'category_path' => $this->localPath($category),
            ],
            'counts' => [
                'products_count' => $productsCount,
                'children_count' => $childrenCount,
                'descendants_products_count' => $descendantsProductsCount,
                'mappings_count' => $mappings->count(),
            ],
            'count_sources' => $this->countSources(),
            'samples' => [
                'product_ids' => array_column($blockingProducts, 'product_id'),
                'blocking_products' => $blockingProducts,
                'children' => $children->map(fn (PartCategory $child): array => ['id' => (string) $child->id, 'name' => $child->name, 'category_path' => $this->localPath($child)])->values()->all(),
            ],
            'mappings' => [
                'has_marketplace_mapping' => $mappings->isNotEmpty(),
                'has_ebay_mapping' => $mappings->contains(fn (MarketplaceCategoryMapping $mapping): bool => str_starts_with((string) $mapping->channel, 'ebay')),
                'has_allegro_mapping' => $mappings->contains(fn (MarketplaceCategoryMapping $mapping): bool => str_starts_with((string) $mapping->channel, 'allegro')),
                'has_ovoko_mapping' => $mappings->contains(fn (MarketplaceCategoryMapping $mapping): bool => (string) $mapping->channel === 'ovoko'),
                'items' => $mappingItems,
            ],
        ];
    }

    public function hardDelete(PartCategory $category): array
    {
        $productsCount = DB::table('parts')->where('category_id', $category->id)->count();
        if ($productsCount > 0) {
            throw new \RuntimeException('Hard delete zablokowany: kategoria ma produkty przypisane bezpośrednio przez parts.category_id.');
        }

        DB::transaction(fn () => $category->delete());

        return $this->clearCaches();
    }

    private function countSources(): array
    {
        return [
            'products_count' => 'Bezpośredni licznik: COUNT(*) FROM parts WHERE parts.category_id = part_categories.id. Nie używa potomków ani cache woo_product_count.',
            'descendants_products_count' => 'Licznik potomków: COUNT(*) FROM parts WHERE parts.category_id IN (rekurencyjne dzieci kategorii). Nie jest doliczany do kolumny Produkty.',
            'woo_product_count' => 'Cache WooCommerce part_categories.woo_product_count nie zasila kolumny Produkty ani blokady hard delete.',
        ];
    }

    private function descendantIds(int $categoryId): array
    {
        $childrenByParent = PartCategory::query()->get(['id', 'parent_id'])->groupBy(fn (PartCategory $category): int => (int) ($category->parent_id ?? 0));
        $ids = [];
        $walk = function (int $parentId) use (&$walk, &$ids, $childrenByParent): void {
            foreach ($childrenByParent->get($parentId, collect()) as $child) {
                $ids[] = (int) $child->id;
                $walk((int) $child->id);
            }
        };
        $walk($categoryId);

        return array_values(array_unique($ids));
    }

    public function clearCaches(): array
    {
        $cleared = [CategoryTreeService::CACHE_KEY, 'storefront.category_tree.v1', 'marketplace_mapper.local_tree'];
        Cache::forget(CategoryTreeService::CACHE_KEY);
        Cache::forget('storefront.category_tree.v1');
        Cache::forget('marketplace_mapper.local_tree');
        if (function_exists('opcache_reset')) @opcache_reset();
        try { Artisan::call('view:clear'); $cleared[] = 'laravel.views'; } catch (Throwable) {}
        try { Artisan::call('cache:clear'); $cleared[] = 'laravel.cache'; } catch (Throwable) {}

        return array_values(array_unique($cleared));
    }

    private function localPath(PartCategory $category): string
    {
        return $category->category_path ?: $category->full_slug_path ?: $category->name;
    }
}

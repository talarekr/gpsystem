<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class LocalCategoryDeleteSafetyService
{
    private const CACHE_KEYS = [
        'storefront.category_tree.v2',
        'storefront.category_tree.v1',
        'marketplace_mapper.local_tree',
        'laravel.views',
        'laravel.cache',
    ];


    public function inspect(int $categoryId): array
    {
        $analysis = $this->analyze($categoryId);

        return $analysis + [
            'exists' => $analysis['name'] !== null,
            'counts' => [
                'products_count' => $analysis['products_count'],
                'children_count' => $analysis['children_count'],
                'descendants_products_count' => $analysis['descendants_products_count'],
                'mappings_count' => count($analysis['mapping_details']),
            ],
            'samples' => [
                'product_ids' => $analysis['sample_product_ids'],
                'direct_products' => $analysis['direct_products'],
                'blocking_products' => $analysis['direct_products'],
                'children' => $analysis['children_sample'],
            ],
            'mappings' => [
                'has_marketplace_mapping' => $analysis['has_marketplace_mapping'],
                'has_ebay_mapping' => $analysis['has_ebay_mapping'],
                'has_allegro_mapping' => $analysis['has_allegro_mapping'],
                'has_ovoko_mapping' => $analysis['has_ovoko_mapping'],
                'items' => $analysis['mapping_details'],
            ],
        ];
    }

    public function hardDelete(PartCategory $category): array
    {
        $result = $this->deleteMany([(int) $category->id], auth()->id());

        if ($result['deleted'] === []) {
            throw new \RuntimeException('Hard delete zablokowany: kategoria nie spełnia warunków bezpieczeństwa.');
        }

        return $result['cache_cleared'];
    }

    public function analyzeMany(array $categoryIds): array
    {
        return collect($categoryIds)
            ->map(fn (int $categoryId): array => $this->analyze($categoryId))
            ->values()
            ->all();
    }

    public function analyze(int $categoryId): array
    {
        $category = PartCategory::query()->find($categoryId);

        if (! $category instanceof PartCategory) {
            return [
                'id' => $categoryId,
                'name' => null,
                'category_path' => null,
                'products_count' => 0,
                'children_count' => 0,
                'descendants_products_count' => 0,
                'sample_product_ids' => [],
                'direct_products' => [],
                'children_sample' => [],
                'has_marketplace_mapping' => false,
                'has_ovoko_mapping' => false,
                'has_allegro_mapping' => false,
                'has_ebay_mapping' => false,
                'mapping_details' => [],
                'can_delete' => false,
                'blockers' => ['category_not_found'],
            ];
        }

        $childrenCount = PartCategory::query()->where('parent_id', $category->id)->count();
        $children = PartCategory::query()
            ->where('parent_id', $category->id)
            ->ordered()
            ->limit(20)
            ->get(['id', 'name', 'category_path']);

        $descendantIds = $this->descendantIds((int) $category->id);
        $productsCount = Part::query()->where('category_id', $category->id)->count();
        $descendantsProductsCount = $descendantIds === []
            ? 0
            : Part::query()->whereIn('category_id', $descendantIds)->count();
        $mappings = MarketplaceCategoryMapping::query()
            ->where('local_category_id', $category->id)
            ->orderBy('channel')
            ->get();
        $channels = $mappings->pluck('channel')->map(fn ($channel) => strtolower((string) $channel))->all();

        $blockers = [];
        if ($productsCount > 0) $blockers[] = 'category_has_direct_products';
        if ($childrenCount > 0) $blockers[] = 'category_has_children';
        if ($descendantsProductsCount > 0) $blockers[] = 'descendants_have_products';
        if ($mappings->count() > 0) $blockers[] = 'category_has_marketplace_mappings';

        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'category_path' => $category->category_path,
            'products_count' => $productsCount,
            'children_count' => $childrenCount,
            'descendants_products_count' => $descendantsProductsCount,
            'sample_product_ids' => Part::query()->where('category_id', $category->id)->limit(20)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'direct_products' => $this->productRows(Part::query()->where('category_id', $category->id)->limit(20)->get()),
            'children_sample' => $children->map(fn (PartCategory $child): array => [
                'id' => (int) $child->id,
                'name' => $child->name,
                'category_path' => $child->category_path,
            ])->all(),
            'has_marketplace_mapping' => $mappings->count() > 0,
            'has_ovoko_mapping' => $this->hasChannel($channels, 'ovoko'),
            'has_allegro_mapping' => $this->hasChannel($channels, 'allegro'),
            'has_ebay_mapping' => $this->hasChannel($channels, 'ebay'),
            'mapping_details' => $mappings->map(fn (MarketplaceCategoryMapping $mapping): array => [
                'id' => (int) $mapping->id,
                'channel' => $mapping->channel,
                'external_category_id' => $mapping->external_category_id,
                'external_category_name' => $mapping->external_category_name,
                'external_category_path' => $mapping->external_category_path,
                'source' => $mapping->source,
                'confidence' => $mapping->confidence,
                'is_blocked' => (bool) $mapping->is_blocked,
            ])->all(),
            'can_delete' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    public function deleteMany(array $categoryIds, ?int $userId = null): array
    {
        $deleted = [];
        $blocked = [];

        foreach ($categoryIds as $categoryId) {
            $analysis = $this->analyze((int) $categoryId);
            $deletedNow = false;

            if ($analysis['can_delete'] === true) {
                PartCategory::query()->whereKey($categoryId)->delete();
                $deletedNow = true;
                $deleted[] = $analysis;
            } else {
                $blocked[] = $analysis;
            }

            Log::info('Local PartCategory hard delete safety result', [
                'user_id' => $userId,
                'category_id' => $analysis['id'],
                'name' => $analysis['name'],
                'category_path' => $analysis['category_path'],
                'counts_before_delete' => [
                    'products_count' => $analysis['products_count'],
                    'children_count' => $analysis['children_count'],
                    'descendants_products_count' => $analysis['descendants_products_count'],
                ],
                'blockers' => $analysis['blockers'],
                'deleted' => $deletedNow,
                'cache_cleared' => false,
            ]);
        }

        $cacheCleared = $deleted === [] ? [] : $this->clearCaches();

        if ($cacheCleared !== []) {
            foreach ($deleted as $item) {
                Log::info('Local PartCategory hard delete cache cleared', [
                    'user_id' => $userId,
                    'category_id' => $item['id'],
                    'name' => $item['name'],
                    'category_path' => $item['category_path'],
                    'cache_cleared' => $cacheCleared,
                ]);
            }
        }

        return [
            'deleted' => $deleted,
            'blocked' => $blocked,
            'cache_cleared' => $cacheCleared,
        ];
    }

    public function clearCaches(): array
    {
        Cache::forget('storefront.category_tree.v2');
        Cache::forget('storefront.category_tree.v1');
        Cache::forget('marketplace_mapper.local_tree');

        try { Artisan::call('view:clear'); } catch (Throwable) {}
        try { Artisan::call('cache:clear'); } catch (Throwable) {}

        return self::CACHE_KEYS;
    }


    /**
     * @param \Illuminate\Support\Collection<int, Part> $parts
     * @return array<int, array<string, mixed>>
     */
    private function productRows(\Illuminate\Support\Collection $parts): array
    {
        return $parts->map(fn (Part $part): array => [
            'id' => (int) $part->id,
            'product_id' => (int) $part->id,
            'title' => $part->name,
            'name' => $part->name,
            'sku' => $part->sku,
            'internal_code' => $part->sku,
            'main_code' => $part->part_number ?: $part->manufacturer_code,
            'oem' => $part->oem_number,
            'category_id' => $part->category_id ? (int) $part->category_id : null,
            'current_category_id' => $part->category_id ? (int) $part->category_id : null,
            'edit_url' => '/admin/parts/'.((int) $part->id).'/edit',
        ])->all();
    }

    private function hasChannel(array $channels, string $needle): bool
    {
        foreach ($channels as $channel) {
            if ($channel === $needle || str_starts_with($channel, $needle.'_') || str_starts_with($channel, $needle.'-')) {
                return true;
            }
        }

        return false;
    }

    private function descendantIds(int $categoryId): array
    {
        $all = [];
        $current = [$categoryId];

        while ($current !== []) {
            $children = PartCategory::query()
                ->whereIn('parent_id', $current)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $children = array_values(array_diff($children, $all));
            $all = array_values(array_unique(array_merge($all, $children)));
            $current = $children;
        }

        return $all;
    }
}

<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategoryMapping;
use App\Models\PartCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DebugCategoryMarketplaceMappingsController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    /** @var array<int, list<int>> */
    private array $childrenByParent = [];

    /** @var array<int, int> */
    private array $productsCountByCategory = [];

    /** @var array<int, int> */
    private array $descendantsProductsCountCache = [];

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        if (! Schema::hasTable('part_categories')) {
            return response()->json($this->safetyFlags() + [
                'ok' => false,
                'error_message' => 'Missing part_categories table.',
            ], 500);
        }

        $sampleLimit = max(1, min(1000, (int) $request->integer('sample_limit', 200)));
        $onlyPublic = $request->boolean('only_public', true);
        $onlyWithProducts = $request->boolean('only_with_products', true);

        /** @var Collection<int, PartCategory> $categories */
        $categories = PartCategory::query()
            ->with(['marketplaceMappings' => fn ($query) => $query->orderBy('channel')])
            ->ordered()
            ->get();

        $this->childrenByParent = $categories
            ->groupBy(fn (PartCategory $category): int => (int) ($category->parent_id ?? 0))
            ->map(fn (Collection $children): array => $children->pluck('id')->map(fn ($id): int => (int) $id)->all())
            ->all();

        $this->productsCountByCategory = Schema::hasTable('parts')
            ? \App\Models\Part::query()
                ->selectRaw('category_id, COUNT(*) as aggregate')
                ->whereNotNull('category_id')
                ->groupBy('category_id')
                ->pluck('aggregate', 'category_id')
                ->map(fn ($count): int => (int) $count)
                ->all()
            : [];

        $rows = $categories
            ->map(fn (PartCategory $category): array => $this->categoryRow($category))
            ->filter(fn (array $row): bool => ! $onlyPublic || $row['is_public'])
            ->filter(fn (array $row): bool => ! $onlyWithProducts || ($row['products_count'] + $row['descendants_products_count']) > 0)
            ->filter(fn (array $row): bool => ! $request->boolean('only_missing_ovoko') || ! $row['has_ovoko_mapping'])
            ->filter(fn (array $row): bool => ! $request->boolean('only_missing_allegro') || ! $row['has_allegro_mapping'])
            ->filter(fn (array $row): bool => ! $request->boolean('leaf_only') || $row['children_count'] === 0)
            ->filter(fn (array $row): bool => ! $request->boolean('parents_only') || $row['children_count'] > 0)
            ->values();

        return response()->json($this->safetyFlags() + [
            'ok' => true,
            'filters' => [
                'only_public' => $onlyPublic,
                'only_with_products' => $onlyWithProducts,
                'only_missing_ovoko' => $request->boolean('only_missing_ovoko'),
                'only_missing_allegro' => $request->boolean('only_missing_allegro'),
                'leaf_only' => $request->boolean('leaf_only'),
                'parents_only' => $request->boolean('parents_only'),
                'sample_limit' => $sampleLimit,
            ],
            'total_matching_count' => $rows->count(),
            'returned_count' => min($rows->count(), $sampleLimit),
            'summary' => [
                'missing_ovoko_count' => $rows->where('has_ovoko_mapping', false)->count(),
                'missing_allegro_count' => $rows->where('has_allegro_mapping', false)->count(),
                'needs_review_count' => $rows->where('needs_review', true)->count(),
            ],
            'categories' => $rows->take($sampleLimit)->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function categoryRow(PartCategory $category): array
    {
        $mappings = $category->marketplaceMappings;
        $ovokoMapping = $this->firstMapping($mappings, ['ovoko']);
        $allegroMapping = $this->firstMapping($mappings, ['allegro_main', 'allegro']);
        $ebayMappings = $mappings->whereIn('channel', ['ebay', 'ebay_de', 'ebay_fr'])->values();
        $productsCount = $this->productsCountByCategory[(int) $category->id] ?? 0;
        $descendantsProductsCount = $this->descendantsProductsCount((int) $category->id);
        $hasOvokoMapping = $this->hasUsableMapping($ovokoMapping);
        $hasAllegroMapping = $this->hasUsableMapping($allegroMapping);
        $hasEbayMapping = $ebayMappings->contains(fn (MarketplaceCategoryMapping $mapping): bool => $this->hasUsableMapping($mapping));
        $hasProductsInBranch = ($productsCount + $descendantsProductsCount) > 0;

        return [
            'local_category_id' => (int) $category->id,
            'local_category_name' => $category->name,
            'public_name' => $category->public_name,
            'category_path' => $category->category_path ?: $category->full_slug_path,
            'products_count' => $productsCount,
            'children_count' => count($this->childrenByParent[(int) $category->id] ?? []),
            'descendants_products_count' => $descendantsProductsCount,
            'has_ovoko_mapping' => $hasOvokoMapping,
            'has_allegro_mapping' => $hasAllegroMapping,
            'has_ebay_mapping' => $hasEbayMapping,
            'ovoko_mapping_details' => $this->mappingDetails($ovokoMapping),
            'allegro_mapping_details' => $this->mappingDetails($allegroMapping),
            'ebay_mapping_details' => $ebayMappings->map(fn (MarketplaceCategoryMapping $mapping): array => $this->mappingDetails($mapping))->all(),
            'needs_ovoko_mapping' => $hasProductsInBranch && ! $hasOvokoMapping,
            'needs_allegro_mapping' => $hasProductsInBranch && ! $hasAllegroMapping,
            'needs_review' => $hasProductsInBranch && (! $hasOvokoMapping || ! $hasAllegroMapping),
            'is_public' => $this->isPublic($category),
        ];
    }

    private function descendantsProductsCount(int $categoryId): int
    {
        if (array_key_exists($categoryId, $this->descendantsProductsCountCache)) {
            return $this->descendantsProductsCountCache[$categoryId];
        }

        $count = 0;
        foreach ($this->childrenByParent[$categoryId] ?? [] as $childId) {
            $count += ($this->productsCountByCategory[$childId] ?? 0) + $this->descendantsProductsCount($childId);
        }

        return $this->descendantsProductsCountCache[$categoryId] = $count;
    }

    /** @param Collection<int, MarketplaceCategoryMapping> $mappings */
    private function firstMapping(Collection $mappings, array $channels): ?MarketplaceCategoryMapping
    {
        return $mappings->first(fn (MarketplaceCategoryMapping $mapping): bool => in_array($mapping->channel, $channels, true));
    }

    private function hasUsableMapping(?MarketplaceCategoryMapping $mapping): bool
    {
        return $mapping instanceof MarketplaceCategoryMapping && filled($mapping->external_category_id) && ! $mapping->is_blocked;
    }

    /** @return array<string, mixed>|null */
    private function mappingDetails(?MarketplaceCategoryMapping $mapping): ?array
    {
        if (! $mapping instanceof MarketplaceCategoryMapping) {
            return null;
        }

        return $mapping->only([
            'id', 'channel', 'external_category_id', 'external_category_name', 'external_category_path',
            'source', 'confidence', 'is_blocked', 'block_reason', 'shipping_group', 'fulfillment_policy_id',
            'notes', 'metadata', 'imported_at', 'updated_at',
        ]);
    }

    private function isPublic(PartCategory $category): bool
    {
        if (Schema::hasColumn($category->getTable(), 'is_visible') && $category->is_visible === false) {
            return false;
        }

        return ! $category->isSystemUncategorized();
    }

    /** @return array<string, bool> */
    private function safetyFlags(): array
    {
        return [
            'read_only' => true,
            'local_update' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'products_changed' => false,
            'offers_changed' => false,
            'mappings_changed' => false,
        ];
    }
}

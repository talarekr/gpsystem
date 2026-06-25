<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCategoryMapping;
use App\Models\PartCategory;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class MarketplaceCategoryMapperController extends Controller
{
    private const CHANNELS = ['allegro_main', 'ovoko', 'ebay'];
    private const TREE_CHANNELS = ['allegro_main' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
    private const ALLEGRO_DEFAULT_PARENT_EXTERNAL_ID = '620';
    private const EBAY_DE_ROOT_EXTERNAL_ID = '131090';
    private const EBAY_DE_DEFAULT_SUBTREE_NAME = 'Autoteile & Zubehör';

    public function index(): View
    {
        return view('admin.marketplace-category-mapper.index', [
            'channels' => [
                ['code' => 'allegro_main', 'label' => 'Allegro'],
                ['code' => 'ovoko', 'label' => 'Ovoko'],
                ['code' => 'ebay', 'label' => 'eBay'],
            ],
            'endpoints' => [
                'localTree' => route('admin.marketplace-category-mapper.tree.local'),
                'channelTree' => route('admin.marketplace-category-mapper.tree.channel', ['channel' => '__CHANNEL__']),
                'mapping' => route('admin.marketplace-category-mapper.mapping.show', ['local_category_id' => '__ID__']),
                'save' => route('admin.marketplace-category-mapper.mapping.save', ['local_category_id' => '__ID__']),
                'deleteInspect' => route('admin.marketplace-category-mapper.delete.inspect', ['local_category_id' => '__ID__']),
                'delete' => route('admin.marketplace-category-mapper.delete', ['local_category_id' => '__ID__']),
            ],
        ]);
    }

    public function localTree(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $parentId = $request->query('parent_id');
        $showHidden = $request->boolean('show_hidden', false);

        $categories = PartCategory::query()
            ->select(array_values(array_filter(['id', 'parent_id', 'name', 'category_path', 'full_slug_path', 'woo_product_count', Schema::hasColumn('part_categories', 'is_visible') ? 'is_visible' : null])))
            ->withCount([
                'children as visible_children_count' => fn ($query) => $showHidden ? $query : $query->visibleForPublic(),
                'parts',
            ])
            ->when(! $showHidden, fn ($query) => $query->visibleForPublic())
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('category_path', 'like', "%{$q}%")->orWhere('full_slug_path', 'like', "%{$q}%")))
            ->when($q === '', fn ($query) => filled($parentId) ? $query->where('parent_id', $parentId) : $query->whereNull('parent_id'))
            ->limit($q !== '' ? 50 : 200)
            ->get();

        $items = $categories->map(fn (PartCategory $category): array => [
            'id' => (string) $category->id,
            'parent_id' => $category->parent_id ? (string) $category->parent_id : null,
            'name' => $category->name,
            'path' => $category->category_path ?: $this->localPath($category),
            'has_children' => $category->visible_children_count > 0,
            'products_count' => $category->woo_product_count ?? $category->parts_count,
            'is_visible' => Schema::hasColumn('part_categories', 'is_visible') ? (bool) $category->is_visible : true,
        ])->all();

        return response()->json(['items' => $this->sortTreeItems($items, true), 'show_hidden' => $showHidden]);
    }

    public function channelTree(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            return response()->json(['items' => [], 'placeholder' => true, 'message' => 'Nieobsługiwany kanał.'], 404);
        }

        $payload = $this->referenceOrExistingExternalCategories($request, $channel);

        return response()->json($payload);
    }

    public function showMapping(int $local_category_id): JsonResponse
    {
        $category = PartCategory::query()->findOrFail($local_category_id);
        $mappings = MarketplaceCategoryMapping::query()
            ->where('local_category_id', $category->id)
            ->whereIn('channel', self::CHANNELS)
            ->get()
            ->keyBy('channel');

        return response()->json([
            'local_category' => ['id' => (string) $category->id, 'name' => $category->name, 'path' => $category->category_path ?: $this->localPath($category)],
            'mappings' => collect(self::CHANNELS)->mapWithKeys(fn (string $channel): array => [$channel => $this->mappingPayload($mappings->get($channel))])->all(),
        ]);
    }

    public function saveMapping(Request $request, int $local_category_id): JsonResponse
    {
        $category = PartCategory::query()->findOrFail($local_category_id);
        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*.channel' => ['required', 'in:'.implode(',', self::CHANNELS)],
            'mappings.*.external_category_id' => ['required', 'string', 'max:255'],
            'mappings.*.external_category_name' => ['required', 'string', 'max:255'],
            'mappings.*.external_category_path' => ['nullable', 'string'],
        ]);

        $saved = [];
        DB::transaction(function () use ($validated, $category, &$saved): void {
            foreach ($validated['mappings'] as $mapping) {
                $metadata = ['updated_by' => Auth::id(), 'updated_from' => 'marketplace_category_mapper'];
                $saved[] = MarketplaceCategoryMapping::query()->updateOrCreate(
                    ['local_category_id' => $category->id, 'channel' => $mapping['channel']],
                    [
                        'external_category_id' => $mapping['external_category_id'],
                        'external_category_name' => $mapping['external_category_name'],
                        'external_category_path' => $mapping['external_category_path'] ?? $mapping['external_category_name'],
                        'local_category_name' => $category->name,
                        'local_category_path' => $category->category_path ?: $this->localPath($category),
                        'source' => 'manual_tree_mapper',
                        'confidence' => 'manual',
                        'is_blocked' => false,
                        'metadata' => $metadata,
                    ]
                );
            }
        });

        return response()->json(['ok' => true, 'saved' => collect($saved)->map(fn ($mapping) => $this->mappingPayload($mapping))->values()]);
    }

    public function inspectLocalCategoryDeleteSafety(int $local_category_id): JsonResponse
    {
        return response()->json($this->localCategoryDeleteSafety($local_category_id));
    }

    public function deleteLocalCategory(Request $request, int $local_category_id): JsonResponse
    {
        $request->validate([
            'confirm' => ['accepted'],
        ]);

        $safety = $this->localCategoryDeleteSafety($local_category_id);

        if (! ($safety['exists'] ?? false)) {
            return response()->json($safety + ['ok' => false, 'message' => 'Kategoria lokalna nie istnieje.'], 404);
        }

        if (! ($safety['can_delete'] ?? false)) {
            return response()->json($safety + ['ok' => false, 'message' => 'Usunięcie kategorii jest zablokowane.'], 422);
        }

        $category = PartCategory::query()->findOrFail($local_category_id);
        $categoryId = (int) $category->id;
        $name = (string) $category->name;
        $path = $category->category_path ?: $this->localPath($category);
        $counts = $safety['counts'];
        DB::transaction(function () use ($category): void {
            $category->delete();
        });

        $cacheCleared = $this->clearCategoryCaches();

        Log::info('marketplace_mapper.local_category_hard_deleted', [
            'user_id' => Auth::id(),
            'category_id' => $categoryId,
            'name' => $name,
            'category_path' => $path,
            'deleted_at' => now()->toIso8601String(),
            'counts_before_delete' => $counts,
            'cache_cleared' => $cacheCleared !== [],
            'cache_keys_cleared' => $cacheCleared,
        ]);

        return response()->json([
            'ok' => true,
            'deleted' => true,
            'category_id' => (string) $categoryId,
            'cache_cleared' => $cacheCleared,
        ]);
    }

    private function ovokoTree(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));
        $parent = $request->query('parent_external_id');
        $categories = Cache::remember('marketplace_mapper.ovoko_categories', now()->addHours(12), function (): array {
            try { return app(MarketplaceApiManager::class)->client('ovoko')->fetchCategories(20)['categories'] ?? []; }
            catch (\Throwable) { return []; }
        });
        $normalized = $this->normalizeOvokoCategories($categories);
        return collect($normalized)
            ->when($q !== '', fn ($c) => $c->filter(fn ($row) => str_contains(mb_strtolower($row['name'].' '.$row['path']), mb_strtolower($q))))
            ->when($q === '', fn ($c) => $c->where('parent_id', filled($parent) ? (string) $parent : null))
            ->take($q !== '' ? 50 : 200)->values()->all();
    }

    private function referenceOrExistingExternalCategories(Request $request, string $channel): array
    {
        $treeChannel = self::TREE_CHANNELS[$channel];
        $debug = $request->boolean('debug');

        if (MarketplaceCategory::query()->where('channel', $treeChannel)->exists()) {
            return $this->referenceCategories($request, $channel, $treeChannel, $debug);
        }

        $items = $this->existingExternalCategories($request, $channel);

        return $this->withTreeMeta([
            'items' => $items,
            'placeholder' => true,
            'message' => 'Lokalne drzewo referencyjne nie jest jeszcze zaimportowane; lista używa obecnych lokalnych mapowań jako bezpiecznego startu.',
        ], $debug, [
            'requested_channel' => $channel,
            'resolved_channel' => $treeChannel,
            'parent_external_id' => $request->query('parent_external_id'),
            'default_parent_external_id' => null,
            'returned_count' => count($items),
            'sample_categories' => array_slice($items, 0, 5),
            'reason_if_empty' => count($items) === 0 ? 'No imported tree and no existing mappings for this channel.' : null,
        ]);
    }

    private function referenceCategories(Request $request, string $requestedChannel, string $treeChannel, bool $debug = false): array
    {
        $q = trim((string) $request->query('q', ''));
        $requestedParent = $request->query('parent_external_id');
        $defaultParent = $this->defaultParentExternalId($treeChannel);
        $parent = filled($requestedParent) ? (string) $requestedParent : $defaultParent;

        $categories = MarketplaceCategory::query()->where('channel', $treeChannel)->where('active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('full_path', 'like', "%{$q}%")))
            ->when($q === '', fn ($query) => filled($parent) ? $query->where('parent_external_category_id', (string) $parent) : $query->whereNull('parent_external_category_id'))
            ->limit($q !== '' ? 50 : 200)->get();
        $ids = $categories->pluck('external_category_id')->all();
        $parents = MarketplaceCategory::query()->where('channel', $treeChannel)->whereIn('parent_external_category_id', $ids)->pluck('parent_external_category_id')->all();
        $items = $this->sortTreeItems($categories->map(fn (MarketplaceCategory $category): array => ['id' => (string) $category->external_category_id, 'parent_id' => $category->parent_external_category_id, 'name' => $category->name, 'path' => $category->full_path ?: $category->name, 'has_children' => in_array($category->external_category_id, $parents, true)])->all());

        return $this->withTreeMeta([
            'items' => $items,
            'placeholder' => false,
            'breadcrumb' => $q === '' && filled($parent) ? $this->breadcrumb($treeChannel, (string) $parent) : [],
        ], $debug, [
            'requested_channel' => $requestedChannel,
            'resolved_channel' => $treeChannel,
            'parent_external_id' => $requestedParent,
            'default_parent_external_id' => $defaultParent,
            'returned_count' => count($items),
            'sample_categories' => array_slice($items, 0, 5),
            'reason_if_empty' => count($items) === 0 ? $this->emptyReason($treeChannel, $parent, $q) : null,
        ]);
    }


    private function defaultParentExternalId(string $treeChannel): ?string
    {
        if ($treeChannel === 'allegro_main') {
            return self::ALLEGRO_DEFAULT_PARENT_EXTERNAL_ID;
        }

        if ($treeChannel !== 'ebay_de') {
            return null;
        }

        $subtree = MarketplaceCategory::query()
            ->where('channel', 'ebay_de')
            ->where('active', true)
            ->where('name', self::EBAY_DE_DEFAULT_SUBTREE_NAME)
            ->where(function ($query): void {
                $query->where('parent_external_category_id', self::EBAY_DE_ROOT_EXTERNAL_ID)
                    ->orWhere('full_path', 'like', '%Auto & Motorrad: Teile%');
            })
            ->orderBy('level')
            ->first();

        if ($subtree) {
            return (string) $subtree->external_category_id;
        }

        if (MarketplaceCategory::query()->where('channel', 'ebay_de')->where('external_category_id', self::EBAY_DE_ROOT_EXTERNAL_ID)->exists()) {
            return self::EBAY_DE_ROOT_EXTERNAL_ID;
        }

        return null;
    }

    private function breadcrumb(string $treeChannel, string $externalCategoryId): array
    {
        $byId = MarketplaceCategory::query()
            ->where('channel', $treeChannel)
            ->get(['external_category_id', 'parent_external_category_id', 'name', 'full_path'])
            ->keyBy(fn (MarketplaceCategory $category): string => (string) $category->external_category_id);
        $breadcrumb = [];
        $current = $byId->get($externalCategoryId);

        while ($current) {
            array_unshift($breadcrumb, [
                'id' => (string) $current->external_category_id,
                'parent_id' => $current->parent_external_category_id,
                'name' => $current->name,
                'path' => $current->full_path ?: $current->name,
            ]);
            $parentId = $current->parent_external_category_id;
            $current = filled($parentId) ? $byId->get((string) $parentId) : null;
        }

        return $breadcrumb;
    }

    private function emptyReason(string $treeChannel, ?string $parent, string $q): string
    {
        if ($q !== '') {
            return 'No categories matched the search query.';
        }

        if (! filled($parent)) {
            return 'No root categories found for resolved channel.';
        }

        return "No children found for parent_external_category_id={$parent} in channel={$treeChannel}.";
    }

    private function withTreeMeta(array $payload, bool $debug, array $diagnostics): array
    {
        if ($debug) {
            $payload['debug'] = $diagnostics;
        }

        return $payload;
    }

    private function existingExternalCategories(Request $request, string $channel): array
    {
        $q = trim((string) $request->query('q', ''));
        $items = MarketplaceCategoryMapping::query()->whereIn('channel', $channel === 'ebay' ? ['ebay', 'ebay_de'] : [$channel])->whereNotNull('external_category_id')
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub->where('external_category_name', 'like', "%{$q}%")->orWhere('external_category_path', 'like', "%{$q}%")->orWhere('external_category_id', 'like', "%{$q}%")))
            ->select(['external_category_id', 'external_category_name', 'external_category_path'])->distinct()->limit(50)->get()
            ->map(fn ($m): array => ['id' => (string) $m->external_category_id, 'parent_id' => null, 'name' => $m->external_category_name ?: 'Kategoria '.$m->external_category_id, 'path' => $m->external_category_path ?: ($m->external_category_name ?: 'ID '.$m->external_category_id), 'has_children' => false])->values()->all();

        return $this->sortTreeItems($items);
    }

    private function normalizeOvokoCategories(array $categories): array
    {
        $rows = collect($categories)->map(function (array $row): array {
            $id = (string) ($row['id'] ?? $row['category_id'] ?? $row['categoryId'] ?? '');
            $parent = $row['parent_id'] ?? $row['parentId'] ?? $row['parent_category_id'] ?? null;
            $name = (string) ($row['pl'] ?? $row['name'] ?? $row['category_name'] ?? $row['en'] ?? $id);
            return ['id' => $id, 'parent_id' => filled($parent) && (string) $parent !== '0' ? (string) $parent : null, 'name' => $name, 'path' => (string) ($row['category_title_path'] ?? $row['category_path'] ?? $row['path'] ?? $name)];
        })->filter(fn ($row) => filled($row['id']))->values();
        $parents = $rows->pluck('parent_id')->filter()->unique()->all();
        return $this->sortTreeItems($rows->map(fn ($row) => $row + ['has_children' => in_array($row['id'], $parents, true)])->all());
    }

    private function mappingPayload(?MarketplaceCategoryMapping $mapping): ?array
    {
        return $mapping ? Arr::only($mapping->toArray(), ['channel', 'external_category_id', 'external_category_name', 'external_category_path', 'source', 'updated_at']) : null;
    }

    private function localCategoryDeleteSafety(int $categoryId): array
    {
        $category = PartCategory::query()->find($categoryId);

        if (! $category) {
            return [
                'exists' => false,
                'can_delete' => false,
                'blockers' => ['Kategoria lokalna nie istnieje.'],
                'counts' => ['products_count' => 0, 'children_count' => 0, 'descendants_products_count' => 0, 'mappings_count' => 0],
                'samples' => ['product_ids' => [], 'children' => []],
                'mappings' => ['has_marketplace_mapping' => false, 'has_ebay_mapping' => false, 'has_allegro_mapping' => false, 'has_ovoko_mapping' => false, 'items' => []],
            ];
        }

        $children = PartCategory::query()->where('parent_id', $category->id)->ordered()->limit(10)->get(['id', 'name', 'category_path']);
        $childrenCount = PartCategory::query()->where('parent_id', $category->id)->count();
        $productIds = DB::table('parts')->where('category_id', $category->id)->limit(10)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $productsCount = DB::table('parts')->where('category_id', $category->id)->count();
        $descendantIds = $this->localCategoryDescendantIds((int) $category->id);
        $descendantsProductsCount = $descendantIds === [] ? 0 : DB::table('parts')->whereIn('category_id', $descendantIds)->count();
        $mappings = MarketplaceCategoryMapping::query()->where('local_category_id', $category->id)->get();
        $mappingItems = $mappings->map(fn (MarketplaceCategoryMapping $mapping): array => [
            'id' => $mapping->id,
            'channel' => $mapping->channel,
            'external_category_id' => $mapping->external_category_id,
            'external_category_name' => $mapping->external_category_name,
            'external_category_path' => $mapping->external_category_path,
        ])->values()->all();

        $hasEbay = $mappings->contains(fn (MarketplaceCategoryMapping $mapping): bool => str_starts_with((string) $mapping->channel, 'ebay'));
        $hasAllegro = $mappings->contains(fn (MarketplaceCategoryMapping $mapping): bool => str_starts_with((string) $mapping->channel, 'allegro'));
        $hasOvoko = $mappings->contains(fn (MarketplaceCategoryMapping $mapping): bool => (string) $mapping->channel === 'ovoko');

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
                'category_path' => $category->category_path ?: $this->localPath($category),
            ],
            'counts' => [
                'products_count' => $productsCount,
                'children_count' => $childrenCount,
                'descendants_products_count' => $descendantsProductsCount,
                'mappings_count' => $mappings->count(),
            ],
            'samples' => [
                'product_ids' => $productIds,
                'children' => $children->map(fn (PartCategory $child): array => ['id' => (string) $child->id, 'name' => $child->name, 'category_path' => $child->category_path ?: $this->localPath($child)])->values()->all(),
            ],
            'mappings' => [
                'has_marketplace_mapping' => $mappings->isNotEmpty(),
                'has_ebay_mapping' => $hasEbay,
                'has_allegro_mapping' => $hasAllegro,
                'has_ovoko_mapping' => $hasOvoko,
                'items' => $mappingItems,
            ],
        ];
    }

    /** @return array<int, int> */
    private function localCategoryDescendantIds(int $categoryId): array
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

    private function clearCategoryCaches(): array
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

    private function sortTreeItems(array $items, bool $local = false): array
    {
        usort($items, function (array $a, array $b) use ($local): int {
            if ($local) {
                $aUncategorized = $this->isUncategorizedLabel((string) ($a['name'] ?? ''));
                $bUncategorized = $this->isUncategorizedLabel((string) ($b['name'] ?? ''));
                if ($aUncategorized !== $bUncategorized) return $aUncategorized ? -1 : 1;
            }

            return strnatcasecmp($this->sortKey((string) ($a['name'] ?? $a['label'] ?? '')), $this->sortKey((string) ($b['name'] ?? $b['label'] ?? '')));
        });

        return array_values($items);
    }

    private function sortKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (class_exists(\Transliterator::class)) {
            $value = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value) ?: $value;
        }

        return $value;
    }

    private function isUncategorizedLabel(string $value): bool
    {
        return $this->sortKey($value) === 'bez kategorii';
    }
}

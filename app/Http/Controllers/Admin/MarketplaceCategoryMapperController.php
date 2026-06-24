<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategoryMapping;
use App\Models\PartCategory;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MarketplaceCategoryMapperController extends Controller
{
    private const CHANNELS = ['allegro_main', 'ovoko', 'ebay'];

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
            ],
        ]);
    }

    public function localTree(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $parentId = $request->query('parent_id');

        $categories = PartCategory::query()
            ->select(['id', 'parent_id', 'name', 'category_path', 'full_slug_path', 'woo_product_count'])
            ->withCount(['children', 'parts'])
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('category_path', 'like', "%{$q}%")->orWhere('full_slug_path', 'like', "%{$q}%")))
            ->when($q === '', fn ($query) => filled($parentId) ? $query->where('parent_id', $parentId) : $query->whereNull('parent_id'))
            ->ordered()
            ->limit($q !== '' ? 50 : 200)
            ->get();

        return response()->json(['items' => $categories->map(fn (PartCategory $category): array => [
            'id' => (string) $category->id,
            'parent_id' => $category->parent_id ? (string) $category->parent_id : null,
            'name' => $category->name,
            'path' => $category->category_path ?: $this->localPath($category),
            'has_children' => $category->children_count > 0,
            'products_count' => $category->woo_product_count ?? $category->parts_count,
        ])->values()]);
    }

    public function channelTree(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            return response()->json(['items' => [], 'placeholder' => true, 'message' => 'Nieobsługiwany kanał.'], 404);
        }

        if ($channel === 'ovoko') {
            return response()->json(['items' => $this->ovokoTree($request), 'placeholder' => false]);
        }

        return response()->json([
            'items' => $this->existingExternalCategories($request, $channel),
            'placeholder' => true,
            'message' => 'Pełne drzewo nie jest jeszcze zaimportowane; lista używa obecnych lokalnych mapowań jako bezpiecznego startu.',
        ]);
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

    private function existingExternalCategories(Request $request, string $channel): array
    {
        $q = trim((string) $request->query('q', ''));
        return MarketplaceCategoryMapping::query()->where('channel', $channel)->whereNotNull('external_category_id')
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub->where('external_category_name', 'like', "%{$q}%")->orWhere('external_category_path', 'like', "%{$q}%")))
            ->select(['external_category_id', 'external_category_name', 'external_category_path'])->distinct()->orderBy('external_category_name')->limit(50)->get()
            ->map(fn ($m): array => ['id' => (string) $m->external_category_id, 'parent_id' => null, 'name' => $m->external_category_name ?: $m->external_category_id, 'path' => $m->external_category_path ?: $m->external_category_name, 'has_children' => false])->values()->all();
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
        return $rows->map(fn ($row) => $row + ['has_children' => in_array($row['id'], $parents, true)])->all();
    }

    private function mappingPayload(?MarketplaceCategoryMapping $mapping): ?array
    {
        return $mapping ? Arr::only($mapping->toArray(), ['channel', 'external_category_id', 'external_category_name', 'external_category_path', 'source', 'updated_at']) : null;
    }

    private function localPath(PartCategory $category): string
    {
        return $category->category_path ?: $category->full_slug_path ?: $category->name;
    }
}

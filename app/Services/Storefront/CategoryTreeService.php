<?php

namespace App\Services\Storefront;

use App\Models\PartCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CategoryTreeService
{
    public const CACHE_KEY = 'storefront.category_tree.v1';
    public const CACHE_TTL_SECONDS = 600;

    /** @return EloquentCollection<int, PartCategory> */
    public function roots(): EloquentCollection
    {
        return $this->tree()['roots'];
    }

    /** @return Collection<int, PartCategory> */
    public function all(): Collection
    {
        return $this->tree()['all'];
    }

    /** @return array{roots:EloquentCollection<int, PartCategory>, all:Collection<int, PartCategory>} */
    public function tree(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $categories = PartCategory::query()
                ->where(function ($query): void {
                    $query->where('source_system', 'woo')->orWhereNull('source_system');
                })
                ->orderByRaw("case when source_system = 'woo' then 0 else 1 end")
                ->ordered()
                ->get();

            $byParent = $categories->groupBy(fn (PartCategory $category): int => (int) ($category->parent_id ?? 0));

            $attachChildren = function (PartCategory $category) use (&$attachChildren, $byParent): PartCategory {
                $children = $byParent->get((int) $category->id, new EloquentCollection())
                    ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
                    ->values();

                $children->each(fn (PartCategory $child) => $attachChildren($child));
                $category->setRelation('children', new EloquentCollection($children->all()));
                $category->setAttribute('has_products_in_branch', $this->branchHasProducts($category));

                return $category;
            };

            $roots = $byParent->get(0, new EloquentCollection())
                ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
                ->values();

            $roots->each(fn (PartCategory $root) => $attachChildren($root));

            return ['roots' => new EloquentCollection($roots->all()), 'all' => $categories->keyBy('id')];
        });
    }

    public function url(PartCategory $category): string
    {
        $path = trim((string) ($category->full_slug_path ?: $category->slug), '/');

        return url('/kategoria-produktu/'.($path !== '' ? $path : $category->id));
    }

    public function findByPublicPath(string $path): ?PartCategory
    {
        $path = trim($path, '/');
        $lastSegment = collect(explode('/', $path))->filter()->last();

        return $this->all()->first(function (PartCategory $category) use ($path, $lastSegment): bool {
            return $category->full_slug_path === $path
                || $category->category_path === $path
                || $category->slug === $path
                || $category->slug === $lastSegment;
        });
    }

    /** @return array<int, int> */
    public function categoryAndDescendantIds(PartCategory $category): array
    {
        $ids = [(int) $category->id];

        $walk = function (PartCategory $node) use (&$walk, &$ids): void {
            $node->children->each(function (PartCategory $child) use (&$walk, &$ids): void {
                $ids[] = (int) $child->id;
                $walk($child);
            });
        };

        $walk($category);

        return array_values(array_unique($ids));
    }

    /** @return EloquentCollection<int, PartCategory> */
    public function ancestors(PartCategory $category): EloquentCollection
    {
        $ancestors = [];
        $current = $category;
        $all = $this->all();

        while ($current->parent_id && $parent = $all->get($current->parent_id)) {
            array_unshift($ancestors, $parent);
            $current = $parent;
        }

        return new EloquentCollection($ancestors);
    }

    /** @return array<int, array{label:string,url:string}> */
    public function shortcuts(): array
    {
        $definitions = [
            'Silniki' => ['kompletne-silniki', 'Silniki kompletne', 'silniki'],
            'Skrzynia biegów' => ['automatyczna-skrzynia-biegow', 'skrzynia biegów', 'skrzynie'],
            'Filtry DPF' => ['filtr-czastek-stalych-katalizator-fap-dpf', 'dpf'],
            'Felgi' => ['felgi-aluminiowe', 'felgi'],
            'Fotele' => ['komplety-foteli-boczkow-podsufitki-dywanikow', 'fotele'],
            'Zwrotnice' => ['zwrotnica-kola-przedniego', 'zwrotnice'],
        ];

        return collect($definitions)->map(function (array $needles, string $label): array {
            $category = $this->findShortcutCategory($needles);

            return ['label' => $label, 'url' => $category ? $this->url($category) : route('storefront.catalog', ['q' => $label])];
        })->values()->all();
    }

    private function findShortcutCategory(array $needles): ?PartCategory
    {
        return $this->all()->first(function (PartCategory $category) use ($needles): bool {
            $haystack = Str::lower(implode(' ', [$category->full_slug_path, $category->slug, $category->name]));

            return collect($needles)->contains(fn (string $needle): bool => str_contains($haystack, Str::lower($needle)));
        });
    }

    private function branchHasProducts(PartCategory $category): bool
    {
        return (int) $category->woo_product_count > 0
            || $category->children->contains(fn (PartCategory $child): bool => (bool) $child->has_products_in_branch);
    }
}

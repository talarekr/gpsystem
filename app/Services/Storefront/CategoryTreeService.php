<?php

namespace App\Services\Storefront;

use App\Models\JarekFrProduct;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CategoryTreeService
{
    public const CACHE_KEY = 'storefront.category_tree.v2';

    public const CACHE_TTL_SECONDS = 600;

    private ?array $frenchTree = null;

    private ?string $explicitLocale = null;

    private ?bool $hasVisibilityColumn = null;

    /** Read-only diagnostics can inspect the FR structure while the admin host is PL. */
    public function forLocale(string $locale): self
    {
        if (! in_array($locale, ['fr', 'pl'], true)) {
            throw new \InvalidArgumentException('Unsupported catalog tree locale.');
        }
        $tree = clone $this;
        $tree->explicitLocale = $locale;
        $tree->frenchTree = null;

        return $tree;
    }

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
        $isFrenchStorefront = ($this->explicitLocale ?? request()->attributes->get('storefront_locale')) === 'fr';
        if ($isFrenchStorefront && $this->frenchTree !== null) {
            return $this->frenchTree;
        }

        // Cache only the base tree. Translation relations are refreshed outside that cache.
        $buildTree = function () use ($isFrenchStorefront): array {
            $categories = PartCategory::query()
                ->where(function ($query): void {
                    $query->where('source_system', 'woo')->orWhereNull('source_system');
                })
                ->visibleForPublic()
                ->orderByRaw("case when source_system = 'woo' then 0 else 1 end")
                ->ordered()
                ->get();

            $productCounts = Part::query()
                ->storefrontVisible()
                ->whereNotNull('category_id')
                ->selectRaw('category_id, count(*) as aggregate')
                ->groupBy('category_id')
                ->pluck('aggregate', 'category_id');

            $plProductCounts = $productCounts->all();

            // Supplier exposure belongs only to this existing FR category. Keep the PL cache independent.
            if ($isFrenchStorefront && Schema::hasTable('jarek_fr_products')) {
                $target = $categories->firstWhere('full_slug_path', FrenchMixedCatalog::CATEGORY_PATH);
                if ($target) {
                    $productCounts[$target->id] = (int) ($productCounts[$target->id] ?? 0)
                        + JarekFrProduct::query()->active()->count();
                }
            }

            $byParent = $categories->groupBy(fn (PartCategory $category): int => (int) ($category->parent_id ?? 0));

            $attachChildren = function (PartCategory $category) use (&$attachChildren, $byParent, $productCounts, $plProductCounts): PartCategory {
                $children = $byParent->get((int) $category->id, new EloquentCollection)
                    ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
                    ->values();

                $children->each(fn (PartCategory $child) => $attachChildren($child));

                $visibleChildren = $children
                    ->filter(fn (PartCategory $child): bool => (bool) $child->has_products_in_branch)
                    ->values();

                $category->setRelation('children', new EloquentCollection($visibleChildren->all()));
                $category->setAttribute('storefront_product_count', max(
                    (int) ($category->woo_product_count ?? 0),
                    (int) ($productCounts[$category->id] ?? 0),
                ));
                $category->setAttribute('has_products_in_branch', $this->branchHasProducts($category));
                $category->setAttribute('has_products_in_pl_branch', max((int) ($category->woo_product_count ?? 0), (int) ($plProductCounts[$category->id] ?? 0)) > 0
                    || $children->contains(fn (PartCategory $child): bool => (bool) $child->has_products_in_pl_branch));

                return $category;
            };

            $roots = $byParent->get(0, new EloquentCollection)
                ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
                ->values();

            $roots->each(fn (PartCategory $root) => $attachChildren($root));

            $visibleRoots = $roots
                ->filter(fn (PartCategory $root): bool => (bool) $root->has_products_in_branch)
                ->values();

            $publicCategories = collect();
            $collectPublic = function (PartCategory $category) use (&$collectPublic, $publicCategories): void {
                $publicCategories->put((int) $category->id, $category);
                $category->children->each(fn (PartCategory $child) => $collectPublic($child));
            };
            $visibleRoots->each(fn (PartCategory $root) => $collectPublic($root));

            return ['roots' => new EloquentCollection($visibleRoots->all()), 'all' => $publicCategories];
        };
        // Build the FR structure from current exposure; a warmed PL tree may omit a Jarek-only branch.
        $tree = $isFrenchStorefront ? $buildTree() : Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, $buildTree);

        if ($isFrenchStorefront) {
            if (Schema::hasTable('category_translations')) {
                (new EloquentCollection($tree['all']->all()))->load('translations');
            }
            $this->frenchTree = $tree;
        }

        return $tree;
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

        $hasVisibilityColumn = $this->hasVisibilityColumn ??= Schema::hasColumn('part_categories', 'is_visible');

        return $this->all()->first(function (PartCategory $category) use ($path, $lastSegment, $hasVisibilityColumn): bool {
            if ($hasVisibilityColumn && ! (bool) $category->is_visible) {
                return false;
            }
            if ($category->isSystemUncategorized()) {
                return false;
            }

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

            if (request()->attributes->get('storefront_locale') === 'fr') {
                $translation = $category?->translationForLocale('fr');
                if ($translation?->isReady() && filled($translation->name)) {
                    $label = $category->storefrontPublicNameForLocale('fr');
                }
            }

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
        return (int) ($category->storefront_product_count ?? $category->woo_product_count) > 0
            || $category->children->contains(fn (PartCategory $child): bool => (bool) $child->has_products_in_branch);
    }
}

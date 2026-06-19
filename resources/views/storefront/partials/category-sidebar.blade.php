@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $categoryRoots ??= $categoryTreeService->roots();
    $activeCategory ??= $category ?? null;
    $activeCategoryId = isset($category) ? (int) $category->id : ($activeCategory ? (int) $activeCategory->id : null);

    $activeAncestors = $activeCategory ? $categoryTreeService->ancestors($activeCategory) : collect();
    $activeRoot = $activeCategory
        ? ($activeAncestors->first() ?? $activeCategory)
        : $categoryRoots->first();

    $activeCategoryIds = $activeAncestors->pluck('id');

    if ($activeCategory) {
        $activeCategoryIds = $activeCategoryIds->push($activeCategory->id);
    }

    $sidebarCategories = $activeRoot?->children ?? collect();
@endphp

<aside class="sf-category-sidebar">
    <h3>Kategoria</h3>

    <select id="storefront-root-category" class="sf-category-sidebar__select" aria-label="Kategoria" onchange="if (this.value) window.location.href = this.value;">
        @foreach($categoryRoots as $rootCategory)
            <option value="{{ $categoryTreeService->url($rootCategory) }}" @selected($activeRoot?->id === $rootCategory->id)>
                {{ $rootCategory->name }}
            </option>
        @endforeach
    </select>

    <h4 class="sf-category-sidebar__section-title">Podkategorie</h4>

    @include('storefront.partials.category-tree', [
        'categories' => $sidebarCategories,
        'activeCategory' => $activeCategory,
        'activeCategoryId' => $activeCategoryId,
        'activeRoot' => $activeRoot,
        'activeCategoryIds' => $activeCategoryIds,
        'level' => 0,
    ])
</aside>

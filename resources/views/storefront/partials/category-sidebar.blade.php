@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $categoryRoots ??= $categoryTreeService->roots();
    $activeCategory ??= null;

    $activeAncestors = $activeCategory ? $categoryTreeService->ancestors($activeCategory) : collect();
    $activeRoot = $activeCategory
        ? ($activeAncestors->first() ?? $activeCategory)
        : $categoryRoots->first();

    $directBranch = null;

    if ($activeCategory && $activeRoot) {
        $directBranch = $activeCategory->parent_id === $activeRoot->id
            ? $activeCategory
            : $activeAncestors->first(fn ($ancestor) => $ancestor->parent_id === $activeRoot->id);
    }

    $requestedOpenCategoryId = request()->integer('open_category') ?: null;
    $openCategoryId = $requestedOpenCategoryId ?: ($directBranch?->id);
    $sidebarCategories = $activeRoot?->children ?? collect();
@endphp

<aside class="sf-category-sidebar">
    <h3>Kategoria</h3>

    <label class="sf-category-sidebar__select-label" for="storefront-root-category">Kategoria</label>
    <select id="storefront-root-category" class="sf-category-sidebar__select" onchange="if (this.value) window.location.href = this.value;">
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
        'activeRoot' => $activeRoot,
        'openCategoryId' => $openCategoryId,
        'level' => 0,
        'maxDepth' => 1,
    ])
</aside>

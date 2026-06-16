@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $categoryRoots ??= $categoryTreeService->roots();
    $activeCategory ??= null;
    $activeAncestorIds = $activeCategory ? $categoryTreeService->ancestors($activeCategory)->pluck('id')->all() : [];
@endphp

<aside class="sf-category-sidebar">
    <h3>Kategorie części</h3>
    @include('storefront.partials.category-tree', [
        'categories' => $categoryRoots,
        'activeCategory' => $activeCategory,
        'activeAncestorIds' => $activeAncestorIds,
        'level' => 0,
        'maxDepth' => 2,
    ])
</aside>

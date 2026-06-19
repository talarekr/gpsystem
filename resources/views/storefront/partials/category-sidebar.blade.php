@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $categoryRoots ??= $categoryTreeService->roots();
    $activeCategory ??= null;
    $activeAncestorIds = [];

    if ($activeCategory) {
        $activeChildren = $activeCategory->children ?? collect();
        $allCategories = $categoryTreeService->all();
        $parentCategory = $activeCategory->parent_id ? $allCategories->get($activeCategory->parent_id) : null;

        $sidebarCategories = $activeChildren->isNotEmpty()
            ? $activeChildren
            : ($parentCategory?->children ?? collect([$activeCategory]));
    } else {
        $sidebarCategories = $categoryRoots;
    }
@endphp

<aside class="sf-category-sidebar">
    <h3>{{ $activeCategory ? 'Kategorie w tej sekcji' : 'Kategorie części' }}</h3>

    @if($activeCategory)
        <a class="sf-category-sidebar__current" href="{{ $categoryTreeService->url($activeCategory) }}">
            <span>{{ $activeCategory->name }}</span>
            @if(! is_null($activeCategory->woo_product_count))
                <small>{{ $activeCategory->woo_product_count }}</small>
            @endif
        </a>
    @endif

    @include('storefront.partials.category-tree', [
        'categories' => $sidebarCategories,
        'activeCategory' => $activeCategory,
        'activeAncestorIds' => $activeAncestorIds,
        'level' => 0,
        'maxDepth' => 0,
    ])
</aside>

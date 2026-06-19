@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $categoryRoots ??= collect();
    $activeCategory ??= $category ?? null;
    $activeCategoryId = isset($category) ? (int) $category->id : ($activeCategory ? (int) $activeCategory->id : null);

    $activeAncestors = $activeCategory ? $categoryTreeService->ancestors($activeCategory) : collect();
    $activeRoot = $activeCategory
        ? ($activeAncestors->first() ?? $activeCategory)
        : ($categoryRoots->first() ?? null);

    $activeCategoryIds = $activeAncestors->pluck('id');

    if ($activeCategory) {
        $activeCategoryIds = $activeCategoryIds->push($activeCategory->id);
    }

    $sidebarCategories = $activeRoot?->children ?? collect();
@endphp

<aside class="sf-category-sidebar">
    <h3>Kategoria</h3>

    @if(($categoryRoots instanceof \Illuminate\Support\Collection ? $categoryRoots->isNotEmpty() : count($categoryRoots)) > 0)
        <select id="storefront-root-category" class="sf-category-sidebar__select" aria-label="Kategoria" onchange="if (this.value) window.location.href = this.value;">
            @foreach($categoryRoots as $rootCategory)
                @php
                    try {
                        $rootUrl = $categoryTreeService->url($rootCategory);
                    } catch (Throwable $exception) {
                        $rootUrl = route('storefront.catalog');
                    }
                @endphp
                <option value="{{ $rootUrl }}" @selected($activeRoot?->id === $rootCategory->id)>
                    {{ $rootCategory->name ?? 'Kategoria' }}
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
            'categoryTreeService' => $categoryTreeService,
        ])
    @else
        <p class="sf-empty">Kategorie zostaną uzupełnione.</p>
    @endif
</aside>

@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $categoryRoots ??= $categoryTreeService->roots();
    $activeCategory ??= null;

    $activeAncestors = $activeCategory ? $categoryTreeService->ancestors($activeCategory) : collect();
    $activeRoot = $activeCategory
        ? ($activeAncestors->first() ?? $activeCategory)
        : $categoryRoots->first();

    $activeCategoryId = $activeCategory ? (int) $activeCategory->id : null;
    $activeCategoryIds = $activeAncestors
        ->pluck('id')
        ->push($activeCategoryId)
        ->filter()
        ->map(fn ($categoryId): int => (int) $categoryId)
        ->unique()
        ->values();

    $sidebarCategories = $activeRoot?->children ?? collect();
    $isRootActive = $activeRoot && $activeCategoryId !== null && (int) $activeRoot->id === $activeCategoryId;
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

    @if($activeRoot)
        <a @class(['sf-category-sidebar__current', 'is-active' => $isRootActive]) href="{{ $categoryTreeService->url($activeRoot) }}">
            <span>{{ $activeRoot->name }}</span>
        </a>
    @endif

    <h4 class="sf-category-sidebar__section-title">Podkategorie</h4>

    @include('storefront.partials.category-tree', [
        'categories' => $sidebarCategories,
        'activeCategoryId' => $activeCategoryId,
        'activeRoot' => $activeRoot,
        'activeCategoryIds' => $activeCategoryIds,
        'level' => 0,
    ])
</aside>

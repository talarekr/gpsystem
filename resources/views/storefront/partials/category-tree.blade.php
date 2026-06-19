@php
    $categories ??= collect();
    $activeCategory ??= null;
    $activeCategoryId ??= $activeCategory ? (int) $activeCategory->id : null;
    $activeRoot ??= null;
    $activeCategoryIds ??= collect();
    $activeParentId ??= $activeCategory?->parent_id ? (int) $activeCategory->parent_id : null;
    $level ??= 0;
@endphp

<ul class="sf-category-tree sf-category-tree--level-{{ $level }}">
    @foreach($categories as $treeCategory)
        @php
            $categoryUrl = $categoryTreeService->url($treeCategory);
            $isCurrentUrl = trim(parse_url($categoryUrl, PHP_URL_PATH), '/') === trim(request()->path(), '/');
            $isActive = $isCurrentUrl;
            $isAncestor = ! $isActive && $activeCategoryIds->contains($treeCategory->id);
            $isActiveParent = ! $isActive && $activeParentId !== null && (int) $treeCategory->id === $activeParentId;
            $hasChildren = $treeCategory->children->isNotEmpty();
            $isOpen = $hasChildren && $activeCategoryIds->contains($treeCategory->id);
        @endphp
        <li class="@class(['is-active' => $isActive, 'is-ancestor' => $isAncestor, 'is-branch' => $isOpen])" data-category-tree-item>
            <div class="sf-category-tree__row">
                @if($hasChildren)
                    <button class="sf-category-tree__toggle" type="button" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-label="{{ $isOpen ? 'Zwiń' : 'Rozwiń' }} {{ $treeCategory->name }}" data-category-tree-toggle>{{ $isOpen ? '−' : '+' }}</button>
                @else
                    <span class="sf-category-tree__toggle sf-category-tree__toggle--empty" aria-hidden="true"></span>
                @endif

                <a @class(['sf-category-tree__link', 'sf-category-tree__link--active' => $isCurrentUrl, 'sf-category-tree__link--active-parent' => $isActiveParent]) href="{{ $categoryUrl }}" @if($isCurrentUrl) aria-current="page" @endif>
                    <span class="sf-category-tree__label">{{ $treeCategory->name }}</span>
                </a>
            </div>
            @if($hasChildren)
                <div @if(! $isOpen) hidden @endif data-category-tree-children>
                    @include('storefront.partials.category-tree', [
                        'categories' => $treeCategory->children,
                        'activeCategory' => $activeCategory,
                        'activeCategoryId' => $activeCategoryId,
                        'activeRoot' => $activeRoot,
                        'activeCategoryIds' => $activeCategoryIds,
                        'activeParentId' => $activeParentId,
                        'level' => $level + 1,
                    ])
                </div>
            @endif
        </li>
    @endforeach
</ul>

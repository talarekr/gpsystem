@php
    $categories ??= collect();
    $activeCategoryId ??= isset($activeCategory) && $activeCategory ? (int) $activeCategory->id : null;
    $activeRoot ??= null;
    $activeCategoryIds ??= collect();
    $level ??= 0;
@endphp

<ul class="sf-category-tree sf-category-tree--level-{{ $level }}">
    @foreach($categories as $treeCategory)
        @php
            $treeCategoryId = (int) $treeCategory->id;
            $isActive = $activeCategoryId !== null && $activeCategoryId === $treeCategoryId;
            $isAncestor = ! $isActive && $activeCategoryIds->contains($treeCategoryId);
            $hasChildren = $treeCategory->children->isNotEmpty();
            $isOpen = $hasChildren && $activeCategoryIds->contains($treeCategoryId);
        @endphp
        <li class="@class(['is-active' => $isActive, 'is-ancestor' => $isAncestor, 'is-branch' => $isOpen])" data-category-tree-item>
            <div class="sf-category-tree__row">
                @if($hasChildren)
                    <button class="sf-category-tree__toggle" type="button" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-label="{{ $isOpen ? 'Zwiń' : 'Rozwiń' }} {{ $treeCategory->name }}" data-category-tree-toggle>{{ $isOpen ? '−' : '+' }}</button>
                @else
                    <span class="sf-category-tree__toggle sf-category-tree__toggle--empty" aria-hidden="true"></span>
                @endif

                <a class="sf-category-tree__link" href="{{ $categoryTreeService->url($treeCategory) }}">
                    <span>{{ $treeCategory->name }}</span>
                </a>
            </div>
            @if($hasChildren)
                <div @if(! $isOpen) hidden @endif data-category-tree-children>
                    @include('storefront.partials.category-tree', [
                        'categories' => $treeCategory->children,
                        'activeCategoryId' => $activeCategoryId,
                        'activeRoot' => $activeRoot,
                        'activeCategoryIds' => $activeCategoryIds,
                        'level' => $level + 1,
                    ])
                </div>
            @endif
        </li>
    @endforeach
</ul>

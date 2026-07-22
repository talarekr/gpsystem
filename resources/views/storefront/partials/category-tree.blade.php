@php
    $categories ??= collect();
    $activeCategory ??= null;
    $activeCategoryId ??= $activeCategory ? (int) $activeCategory->id : null;
    $activeRoot ??= null;
    $activeCategoryIds ??= collect();
    $level ??= 0;
@endphp

<ul class="sf-category-tree sf-category-tree--level-{{ $level }}">
    @foreach($categories as $treeCategory)
        @php
            try {
                $categoryUrl = $categoryTreeService->url($treeCategory);
            } catch (Throwable $exception) {
                $categoryUrl = route('storefront.catalog');
            }
            $isCurrentUrl = trim(parse_url($categoryUrl, PHP_URL_PATH), '/') === trim(request()->path(), '/');
            $isActive = $isCurrentUrl;
            $isAncestor = ! $isActive && $activeCategoryIds->contains($treeCategory->id);
            $children = $treeCategory->children ?? collect();
            $hasChildren = $children->isNotEmpty();
            $isOpen = $hasChildren && $activeCategoryIds->contains($treeCategory->id);
        @endphp
        <li class="@class(['is-active' => $isActive, 'is-ancestor' => $isAncestor, 'is-branch' => $isOpen])" data-category-tree-item>
            <div class="sf-category-tree__row">
                @if($hasChildren)
                    <button class="sf-category-tree__toggle" type="button" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-label="{{ $isOpen ? __('storefront.collapse') : __('storefront.expand') }} {{ $treeCategory->public_name }}" data-category-tree-toggle>{{ $isOpen ? '−' : '+' }}</button>
                @else
                    <span class="sf-category-tree__toggle sf-category-tree__toggle--empty" aria-hidden="true"></span>
                @endif

                <a @class(['sf-category-tree__link', 'sf-category-tree__link--active' => $isCurrentUrl]) href="{{ $categoryUrl }}" @if($isCurrentUrl) aria-current="page" @endif>
                    <span class="sf-category-tree__label">{{ $treeCategory->public_name }}</span>
                </a>
            </div>
            @if($hasChildren)
                <div @if(! $isOpen) hidden @endif data-category-tree-children>
                    @include('storefront.partials.category-tree', [
                        'categories' => $children,
                        'activeCategory' => $activeCategory,
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

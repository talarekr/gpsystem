@php
    $categories ??= collect();
    $activeCategory ??= null;
    $activeRoot ??= null;
    $openCategoryId ??= null;
    $level ??= 0;
    $maxDepth ??= 1;
@endphp

<ul class="sf-category-tree sf-category-tree--level-{{ $level }}">
    @foreach($categories as $treeCategory)
        @php
            $isActive = $activeCategory?->id === $treeCategory->id;
            $hasChildren = $treeCategory->children->isNotEmpty();
            $isOpen = $hasChildren && (int) $openCategoryId === (int) $treeCategory->id;
            $showChildren = $level < $maxDepth && $isOpen;
            $toggleUrl = request()->fullUrlWithQuery(['open_category' => $treeCategory->id]);
        @endphp
        <li class="@class(['is-active' => $isActive, 'is-branch' => $isOpen, 'is-empty' => ! $treeCategory->has_products_in_branch])">
            <div class="sf-category-tree__row">
                @if($level === 0 && $hasChildren)
                    <a class="sf-category-tree__toggle" href="{{ $toggleUrl }}" aria-label="{{ $isOpen ? 'Zwiń' : 'Rozwiń' }} {{ $treeCategory->name }}">{{ $isOpen ? '−' : '+' }}</a>
                @elseif($level === 0)
                    <span class="sf-category-tree__toggle sf-category-tree__toggle--empty" aria-hidden="true"></span>
                @endif

                <a class="sf-category-tree__link" href="{{ $categoryTreeService->url($treeCategory) }}">
                    <span>{{ $treeCategory->name }}</span>
                    @if(! is_null($treeCategory->woo_product_count))
                        <small>{{ $treeCategory->woo_product_count }}</small>
                    @endif
                </a>
            </div>
            @if($showChildren)
                @include('storefront.partials.category-tree', [
                    'categories' => $treeCategory->children,
                    'activeCategory' => $activeCategory,
                    'activeRoot' => $activeRoot,
                    'openCategoryId' => $openCategoryId,
                    'level' => $level + 1,
                    'maxDepth' => $maxDepth,
                ])
            @endif
        </li>
    @endforeach
</ul>

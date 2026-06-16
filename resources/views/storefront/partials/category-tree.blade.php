@php
    $categories ??= collect();
    $activeCategory ??= null;
    $activeAncestorIds ??= [];
    $level ??= 0;
    $maxDepth ??= 2;
@endphp

<ul class="sf-category-tree sf-category-tree--level-{{ $level }}">
    @foreach($categories as $treeCategory)
        @php
            $isActive = $activeCategory?->id === $treeCategory->id;
            $isInActiveBranch = in_array($treeCategory->id, $activeAncestorIds, true) || $isActive;
            $showChildren = $level < $maxDepth && $treeCategory->children->isNotEmpty() && ($level === 0 || $isInActiveBranch || ! $activeCategory);
        @endphp
        <li class="@class(['is-active' => $isActive, 'is-branch' => $isInActiveBranch, 'is-empty' => ! $treeCategory->has_products_in_branch])">
            <a href="{{ $categoryTreeService->url($treeCategory) }}">
                <span>{{ $treeCategory->name }}</span>
                @if(! is_null($treeCategory->woo_product_count))
                    <small>{{ $treeCategory->woo_product_count }}</small>
                @endif
            </a>
            @if($showChildren)
                @include('storefront.partials.category-tree', [
                    'categories' => $treeCategory->children,
                    'activeCategory' => $activeCategory,
                    'activeAncestorIds' => $activeAncestorIds,
                    'level' => $level + 1,
                    'maxDepth' => $maxDepth,
                ])
            @endif
        </li>
    @endforeach
</ul>

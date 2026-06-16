@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $roots = ($storefrontCategoryRoots ?? $categoryTreeService->roots())->take(14);
@endphp

<details class="sf-menu sf-category-menu">
    <summary>☰ Menu</summary>
    <div class="sf-category-menu__panel">
        <a class="sf-category-menu__all" href="{{ route('storefront.catalog') }}">Wszystkie części</a>
        <div class="sf-category-menu__grid">
            @foreach($roots as $root)
                <section class="sf-category-menu__root">
                    <a class="sf-category-menu__root-link" href="{{ $categoryTreeService->url($root) }}">
                        {{ $root->name }}
                        @if(! is_null($root->woo_product_count))<small>{{ $root->woo_product_count }}</small>@endif
                    </a>
                    @if($root->children->isNotEmpty())
                        <ul>
                            @foreach($root->children->take(8) as $child)
                                <li>
                                    <a href="{{ $categoryTreeService->url($child) }}">{{ $child->name }}</a>
                                    @if($child->children->isNotEmpty())
                                        <ul>
                                            @foreach($child->children->take(4) as $grandchild)
                                                <li><a href="{{ $categoryTreeService->url($grandchild) }}">{{ $grandchild->name }}</a></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</details>

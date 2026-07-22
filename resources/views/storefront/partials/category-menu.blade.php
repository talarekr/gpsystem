@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $roots = ($storefrontCategoryRoots ?? $categoryTreeService->roots())->values();
    $activeRootId = optional($roots->first())->id;
    $visibleGrandchildren = 12;
@endphp

<details class="sf-menu sf-category-menu" data-category-menu>
    <summary aria-expanded="false">☰ Menu</summary>
    <div class="sf-category-menu__panel" role="region" aria-label="{{ __('storefront.category_menu') }}">
        <div class="sf-category-menu__desktop">
            <aside class="sf-category-menu__roots" aria-label="{{ __('storefront.featured_categories') }}">
                <a class="sf-category-menu__catalog-link" href="{{ route('storefront.catalog') }}">{{ __('storefront.all_parts') }}</a>
                <div class="sf-category-menu__root-list" role="list">
                    @foreach($roots as $root)
                        <button
                            class="sf-category-menu__root-trigger @if($root->id === $activeRootId) is-active @endif"
                            type="button"
                            data-root-id="{{ $root->id }}"
                            aria-controls="sf-category-menu-branch-{{ $root->id }}"
                            aria-selected="{{ $root->id === $activeRootId ? 'true' : 'false' }}"
                            role="listitem"
                        >
                            <span>{{ $root->public_name }}</span>
                            <span aria-hidden="true">›</span>
                        </button>
                    @endforeach
                </div>
            </aside>

            <div class="sf-category-menu__branches">
                @foreach($roots as $root)
                    <section
                        id="sf-category-menu-branch-{{ $root->id }}"
                        class="sf-category-menu__branch @if($root->id === $activeRootId) is-active @endif"
                        data-root-panel="{{ $root->id }}"
                        @if($root->id !== $activeRootId) hidden @endif
                    >
                        <div class="sf-category-menu__branch-head">
                            <div>
                                <span>Wybrana kategoria</span>
                                <h3>{{ $root->public_name }}</h3>
                            </div>
                            <a href="{{ $categoryTreeService->url($root) }}">Zobacz wszystkie</a>
                        </div>

                        @if($root->children->isNotEmpty())
                            <div class="sf-category-menu__branch-grid">
                                @foreach($root->children as $child)
                                    <section class="sf-category-menu__section">
                                        <a class="sf-category-menu__section-title" href="{{ $categoryTreeService->url($child) }}">
                                            {{ $child->public_name }}
                                        </a>
                                        @if($child->children->isNotEmpty())
                                            <ul>
                                                @foreach($child->children->take($visibleGrandchildren) as $grandchild)
                                                    <li><a href="{{ $categoryTreeService->url($grandchild) }}">{{ $grandchild->public_name }}</a></li>
                                                @endforeach
                                                @if($child->children->count() > $visibleGrandchildren)
                                                    <li><a class="sf-category-menu__more" href="{{ $categoryTreeService->url($child) }}">{{ __('storefront.show_more') }}</a></li>
                                                @endif
                                            </ul>
                                        @else
                                            <p>{{ __('storefront.no_subcategories') }}</p>
                                        @endif
                                    </section>
                                @endforeach
                            </div>
                        @else
                            <div class="sf-category-menu__empty">
                                <p>Ta kategoria nie ma podkategorii w menu.</p>
                                <a href="{{ $categoryTreeService->url($root) }}">{{ __('storefront.go_to_category') }}</a>
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>

        <div class="sf-category-menu__mobile">
            <a class="sf-category-menu__catalog-link" href="{{ route('storefront.catalog') }}">{{ __('storefront.all_parts') }}</a>
            @foreach($roots as $root)
                <details class="sf-category-menu__mobile-root">
                    <summary>{{ $root->public_name }}</summary>
                    <a class="sf-category-menu__mobile-all" href="{{ $categoryTreeService->url($root) }}">Zobacz wszystkie w kategorii</a>
                    @if($root->children->isNotEmpty())
                        <ul>
                            @foreach($root->children as $child)
                                <li>
                                    <a href="{{ $categoryTreeService->url($child) }}">{{ $child->public_name }}</a>
                                    @if($child->children->isNotEmpty())
                                        <ul>
                                            @foreach($child->children->take($visibleGrandchildren) as $grandchild)
                                                <li><a href="{{ $categoryTreeService->url($grandchild) }}">{{ $grandchild->public_name }}</a></li>
                                            @endforeach
                                            @if($child->children->count() > $visibleGrandchildren)
                                                <li><a class="sf-category-menu__more" href="{{ $categoryTreeService->url($child) }}">{{ __('storefront.show_more') }}</a></li>
                                            @endif
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </details>
            @endforeach
        </div>
    </div>
</details>

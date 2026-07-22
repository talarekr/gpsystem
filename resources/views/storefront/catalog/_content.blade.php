@php
    $parts = $parts ?? collect();

    $catalogUrl = '/czesci';

    $resultCount = method_exists($parts, 'total')
        ? $parts->total()
        : (method_exists($parts, 'count') ? $parts->count() : 0);

    $currentSort = $_GET['sort'] ?? '';
    $currentQ = $_GET['q'] ?? '';
    $currentPartNumber = $_GET['part_number'] ?? '';

    $sortableQuery = $_GET;
    unset($sortableQuery['sort'], $sortableQuery['page'], $sortableQuery['token'], $sortableQuery['stage']);
@endphp

<div class="sf-container sf-page">
    <h1>{{ __('storefront.catalog') }}</h1>
    <p class="sf-empty">{{ __('storefront.catalog_intro') }}</p>

    <div class="sf-shop-layout">
        <div class="sf-sidebar-stack">
            <aside class="sf-filters">
                <h3>{{ __('storefront.search_catalog') }}</h3>

                <form method="get" action="{{ $catalogUrl }}">
                    <label>{{ __('storefront.phrase') }}
                        <input type="search" name="q" value="{{ $currentQ }}" placeholder="{{ __('storefront.phrase_placeholder') }}">
                    </label>

                    <label>{{ __('storefront.part_number') }}
                        <input name="part_number" value="{{ $currentPartNumber }}" placeholder="{{ __('storefront.part_number_placeholder') }}">
                    </label>

                    <label>{{ __('storefront.sorting') }}
                        <select name="sort">
                            <option value="" {{ $currentSort === '' ? 'selected' : '' }}>{{ __('storefront.sort_default') }}</option>
                            <option value="price_asc" {{ $currentSort === 'price_asc' ? 'selected' : '' }}>{{ __('storefront.price_asc') }}</option>
                            <option value="price_desc" {{ $currentSort === 'price_desc' ? 'selected' : '' }}>{{ __('storefront.price_desc') }}</option>
                            <option value="name" {{ $currentSort === 'name' ? 'selected' : '' }}>{{ __('storefront.name') }}</option>
                        </select>
                    </label>

                    <button class="sf-btn" type="submit">{{ __('storefront.search') }}</button>
                    <a class="sf-clear" href="{{ $catalogUrl }}">{{ __('storefront.clear') }}</a>
                </form>
            </aside>

            @include('storefront.partials.category-sidebar', ['categoryRoots' => $categoryRoots, 'activeCategory' => null])
        </div>

        <section>
            <div class="sf-toolbar">
                <span>{{ __('storefront.results', ['count' => $resultCount]) }}</span>

                <form method="get" action="{{ $catalogUrl }}">
                    @foreach($sortableQuery as $key => $value)
                        @if(is_array($value))
                            @foreach($value as $item)
                                <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                            @endforeach
                        @elseif($value !== null && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach

                    <select name="sort" onchange="this.form.submit()">
                        <option value="" {{ $currentSort === '' ? 'selected' : '' }}>{{ __('storefront.sort_default') }}</option>
                        <option value="price_asc" {{ $currentSort === 'price_asc' ? 'selected' : '' }}>{{ __('storefront.price_asc') }}</option>
                        <option value="price_desc" {{ $currentSort === 'price_desc' ? 'selected' : '' }}>{{ __('storefront.price_desc') }}</option>
                        <option value="name" {{ $currentSort === 'name' ? 'selected' : '' }}>{{ __('storefront.name') }}</option>
                    </select>
                </form>
            </div>

            <div class="sf-grid sf-grid--3">
                @forelse($parts as $part)
                    @include('storefront.partials.product-card', ['part' => $part])
                @empty
                    <p class="sf-empty">{{ __('storefront.no_products_criteria') }}</p>
                @endforelse
            </div>

            @if(method_exists($parts, 'links'))
                {!! method_exists($parts, 'withQueryString') ? $parts->withQueryString()->links() : $parts->links() !!}
            @endif
        </section>
    </div>
</div>

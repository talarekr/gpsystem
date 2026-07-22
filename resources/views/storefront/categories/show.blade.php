@extends('layouts.storefront')

@section('content')
    <section class="sf-category-hero" aria-labelledby="category-title">
        <div class="sf-container">
            <div class="sf-category-hero__inner">
                <div class="sf-category-hero__content">
                    <h1 id="category-title">{{ $category->public_name }}</h1>
                    <p>
                        {{ __('storefront.category_intro') }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    <div class="sf-container sf-category-breadcrumb-bar">
        @include('storefront.partials.category-breadcrumbs')
    </div>

    <div class="sf-container sf-page">
        <div class="sf-shop-layout">
            <div class="sf-sidebar-stack">
                @include('storefront.partials.filters', ['filterAction' => url()->current(), 'producers' => $producers, 'models' => $models])
                @include('storefront.partials.category-sidebar', ['categoryRoots' => $categoryRoots, 'activeCategory' => $category])
            </div>
            <section>
                <div class="sf-toolbar"><span>{{ __('storefront.results', ['count' => $parts->total()]) }}</span></div>
                <div class="sf-grid sf-grid--3">
                    @forelse($parts as $part)
                        @include('storefront.partials.product-card', ['part' => $part])
                    @empty
                        <p class="sf-empty">{{ __('storefront.no_products_category') }}</p>
                    @endforelse
                </div>
                {{ $parts->links() }}
            </section>
        </div>
    </div>
@endsection

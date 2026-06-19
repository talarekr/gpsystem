@extends('layouts.storefront')

@section('content')
    <section class="sf-category-hero" aria-labelledby="category-title">
        <div class="sf-container sf-category-hero__inner">
            <div class="sf-category-hero__content">
                <h1 id="category-title">{{ $category->name }}</h1>
                <p>
                    W sklepie motoryzacyjnym GP Swiss znajdziesz szeroki wybór oryginalnych, używanych części samochodowych do wielu popularnych marek, takich jak BMW, Mini, Mercedes, Audi czy Volkswagen. Każdy oferowany przez nas produkt jest dokładnie sprawdzany pod kątem jakości i sprawności, dzięki czemu masz pewność, że wybierasz sprawdzony i solidny produkt.
                </p>
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
                <div class="sf-toolbar"><span>{{ $parts->total() }} wyników</span></div>
                <div class="sf-grid sf-grid--3">
                    @forelse($parts as $part)
                        @include('storefront.partials.product-card', ['part' => $part])
                    @empty
                        <p class="sf-empty">Brak produktów w tej kategorii.</p>
                    @endforelse
                </div>
                {{ $parts->links() }}
            </section>
        </div>
    </div>
@endsection

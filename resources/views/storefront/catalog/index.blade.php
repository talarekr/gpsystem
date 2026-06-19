@extends('layouts.storefront')
@section('content')
<div class="sf-container sf-page">
    @include('storefront.partials.breadcrumbs')
    <h1>Sklep GPSwiss</h1>
    <div class="sf-shop-layout">
        <div class="sf-sidebar-stack">
            @include('storefront.partials.filters', ['filterAction'=>route('storefront.catalog'), 'producers' => $producers, 'models' => $models])
            @include('storefront.partials.category-sidebar', ['categoryRoots' => $categoryRoots, 'activeCategory' => null])
        </div>
        <section>
            <div class="sf-toolbar"><span>{{ $parts->total() }} wyników</span><form><input type="hidden" name="q" value="{{ request('q') }}"><select name="sort" onchange="this.form.submit()"><option value="">Sortuj domyślnie</option><option value="price_asc" @selected(request('sort')==='price_asc')>Cena rosnąco</option><option value="price_desc" @selected(request('sort')==='price_desc')>Cena malejąco</option><option value="name" @selected(request('sort')==='name')>Nazwa</option></select></form></div>
            <div class="sf-grid sf-grid--3">@forelse($parts as $part) @include('storefront.partials.product-card', ['part'=>$part]) @empty <p class="sf-empty">Brak produktów dla wybranych filtrów.</p> @endforelse</div>{{ $parts->links() }}
        </section>
    </div>
</div>
@endsection

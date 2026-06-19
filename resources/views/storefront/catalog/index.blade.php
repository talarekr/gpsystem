@extends('layouts.storefront')
@section('content')
@php
    $parts ??= collect();
    $catalogUrl = \Illuminate\Support\Facades\Route::has('storefront.catalog') ? route('storefront.catalog') : url('/czesci');
    $sortableQuery = request()->except(['sort', 'page']);
@endphp
<div class="sf-container sf-page">
    @include('storefront.partials.breadcrumbs')
    <h1>Katalog części GPSwiss</h1>
    <div class="sf-shop-layout">
        <div class="sf-sidebar-stack">
            @include('storefront.partials.filters', ['filterAction'=>$catalogUrl, 'producers' => $producers ?? [], 'models' => $models ?? []])
            @include('storefront.partials.category-sidebar', ['categoryRoots' => $categoryRoots ?? collect(), 'activeCategory' => null, 'categoryTreeService' => $categoryTreeService ?? null])
        </div>
        <section>
            <div class="sf-toolbar"><span>{{ method_exists($parts, 'total') ? $parts->total() : $parts->count() }} wyników</span><form method="get" action="{{ $catalogUrl }}">@foreach($sortableQuery as $key => $value) @if(is_array($value)) @foreach($value as $item)<input type="hidden" name="{{ $key }}[]" value="{{ $item }}">@endforeach @else <input type="hidden" name="{{ $key }}" value="{{ $value }}"> @endif @endforeach<select name="sort" onchange="this.form.submit()"><option value="">Sortuj domyślnie</option><option value="price_asc" @selected(request('sort')==='price_asc')>Cena rosnąco</option><option value="price_desc" @selected(request('sort')==='price_desc')>Cena malejąco</option><option value="name" @selected(request('sort')==='name')>Nazwa</option></select></form></div>
            <div class="sf-grid sf-grid--3">@forelse($parts as $part) @include('storefront.partials.product-card', ['part'=>$part]) @empty <p class="sf-empty">Brak produktów dla wybranych filtrów.</p> @endforelse</div>@if(method_exists($parts, 'links')){{ $parts->links() }}@endif
        </section>
    </div>
</div>
@endsection

@extends('layouts.storefront')

@section('content')
@php
    $sectionUrls = [
        __('storefront.engines') => route('storefront.category', ['path' => 'silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki']),
        __('storefront.gearbox') => route('storefront.category', ['path' => 'uklad-napedowy/skrzynie-biegow-i-inne-elementy/automatyczna-skrzynia-biegow']),
        __('storefront.knuckles') => route('storefront.category', ['path' => 'os-przednia-i-inne-elementy/os-przednia/zwrotnica-kola-przedniego']),
        __('storefront.dpf') => route('storefront.category', ['path' => 'uklad-wydechowy-i-inne-elementy/elementy-systemu-kontroli-spalin/filtr-czastek-stalych-katalizator-fap-dpf']),
    ];
@endphp

<section class="sf-hero"><div class="sf-container sf-hero__grid"><div><h1>{{ __('storefront.hero_title') }}</h1><p>{{ __('storefront.hero_text_1') }}</p><p>{{ __('storefront.hero_text_2') }}</p></div><form class="sf-part-search" action="{{ route('storefront.search') }}" method="get"><label>{{ __('storefront.part_number') }}</label><input name="part_number" placeholder="8E0 953 521D"><button type="submit">{{ __('storefront.search_by_number') }}</button></form></div></section>
<div class="sf-container">
@foreach($sections as $title => $parts)<section class="sf-section sf-product-carousel" data-product-carousel><div class="sf-section__head"><h2>{{ $title }}</h2><a href="{{ $sectionUrls[$title] ?? route('storefront.catalog', ['q' => $title]) }}">{{ __('storefront.show_all') }}</a></div><div class="sf-product-carousel__shell"><div class="sf-product-carousel__track" tabindex="0" data-carousel-track>@forelse($parts as $part)<div class="sf-product-carousel__slide">@include('storefront.partials.product-card', ['part'=>$part])</div>@empty <div class="sf-empty">{{ __('storefront.section_empty') }}</div> @endforelse</div></div><div class="sf-product-carousel__controls"><button class="sf-product-carousel__arrow sf-product-carousel__arrow--prev" type="button" aria-label="{{ __('storefront.carousel_prev', ['title' => $title]) }}" data-carousel-prev>‹</button><div class="sf-product-carousel__pagination" aria-label="{{ __('storefront.carousel_pagination', ['title' => $title]) }}" data-carousel-pagination></div><button class="sf-product-carousel__arrow sf-product-carousel__arrow--next" type="button" aria-label="{{ __('storefront.carousel_next', ['title' => $title]) }}" data-carousel-next>›</button></div></section>@endforeach
<section class="sf-section"><div class="sf-section__head"><h2>{{ __('storefront.our_brands') }}</h2></div><div class="sf-brands">@foreach(['BMW','AUDI','Volkswagen','Skoda'] as $brand)<a href="{{ route('storefront.catalog', ['vehicle_model'=>$brand]) }}">{{ $brand }}</a>@endforeach</div></section>
</div>
@endsection

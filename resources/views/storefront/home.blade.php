@extends('layouts.storefront')

@section('content')
@php
    $sectionUrls = [
        'Silniki kompletne' => 'https://gpsystem.thecamels.pl/kategoria-produktu/silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki',
        'Skrzynie kompletne' => 'https://gpsystem.thecamels.pl/kategoria-produktu/uklad-napedowy/skrzynie-biegow-i-inne-elementy/automatyczna-skrzynia-biegow',
        'Zwrotnice' => 'https://gpsystem.thecamels.pl/kategoria-produktu/os-przednia-i-inne-elementy/os-przednia/zwrotnica-kola-przedniego',
        'Filtry DPF' => 'https://gpsystem.thecamels.pl/kategoria-produktu/uklad-wydechowy-i-inne-elementy/elementy-systemu-kontroli-spalin/filtr-czastek-stalych-katalizator-fap-dpf',
    ];
@endphp

<section class="sf-hero"><div class="sf-container sf-hero__grid"><div><p class="sf-eyebrow">Używane części samochodowe</p><h1>GP SWISS - największy wybór części używanych w Polsce</h1><p>Oryginalne silniki, skrzynie biegów, DPF, elementy zawieszenia i wyposażenia z realnych danych magazynowych.</p><a class="sf-btn sf-btn--light" href="{{ route('storefront.catalog') }}">Przejdź do katalogu</a></div><form class="sf-part-search" action="{{ route('storefront.search') }}" method="get"><label>Numer części</label><input name="part_number" placeholder="8E0 953 521D"><button type="submit">Szukaj po numerze</button></form></div></section>
<div class="sf-container">
@foreach($sections as $title => $parts)<section class="sf-section sf-product-carousel" data-product-carousel><div class="sf-section__head"><h2>{{ $title }}</h2><a href="{{ $sectionUrls[$title] ?? route('storefront.catalog', ['q' => $title]) }}">Pokaż wszystkie</a></div><div class="sf-product-carousel__shell"><div class="sf-product-carousel__track" tabindex="0" data-carousel-track>@forelse($parts as $part)<div class="sf-product-carousel__slide">@include('storefront.partials.product-card', ['part'=>$part])</div>@empty <div class="sf-empty">Produkty pojawią się po imporcie danych dla tej sekcji.</div> @endforelse</div></div><div class="sf-product-carousel__controls"><button class="sf-product-carousel__arrow sf-product-carousel__arrow--prev" type="button" aria-label="Przewiń {{ $title }} w lewo" data-carousel-prev>‹</button><div class="sf-product-carousel__pagination" aria-label="Paginacja karuzeli {{ $title }}" data-carousel-pagination></div><button class="sf-product-carousel__arrow sf-product-carousel__arrow--next" type="button" aria-label="Przewiń {{ $title }} w prawo" data-carousel-next>›</button></div></section>@endforeach
<section class="sf-section"><div class="sf-section__head"><h2>Kategorie części</h2><a href="{{ route('storefront.catalog') }}">Pokaż wszystkie</a></div><div class="sf-category-tiles">@foreach(['Silniki i osprzęt','Skrzynie biegów i napędy','Felgi i opony','Układ kierowniczy','Układ hamulcowy','Oświetlenie','Zawieszenie','Elektronika','Wnętrze / kokpit','Karoseria','Chłodzenie','Akcesoria'] as $cat)<a href="{{ route('storefront.catalog', ['category'=>$cat]) }}">⚙️ {{ $cat }}</a>@endforeach</div></section>
<section class="sf-section"><div class="sf-section__head"><h2>Nasze marki</h2></div><div class="sf-brands">@foreach(['BMW','AUDI','Volkswagen','Skoda'] as $brand)<a href="{{ route('storefront.catalog', ['vehicle_model'=>$brand]) }}">{{ $brand }}</a>@endforeach</div></section>
</div>
@endsection

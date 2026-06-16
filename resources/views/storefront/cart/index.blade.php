@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page">
    @include('storefront.partials.breadcrumbs')

    <div class="sf-cart-head">
        <h1>Koszyk</h1>
        <a class="sf-btn sf-btn--outline" href="{{ route('storefront.catalog') }}">Kontynuuj zakupy</a>
    </div>

    @if($isEmpty)
        <section class="sf-cart-empty">
            <h2>Twój koszyk jest pusty.</h2>
            <p>Dodaj część z karty produktu, aby rozpocząć zamówienie.</p>
            <a class="sf-btn" href="{{ route('storefront.catalog') }}">Przejdź do sklepu</a>
        </section>
    @else
        <div class="sf-cart-layout">
            <form method="post" action="{{ route('storefront.cart.update') }}" class="sf-cart-items" aria-label="Pozycje koszyka">
                @csrf
                @foreach($items as $item)
                    @php
                        $part = $item['current_part'];
                        $productUrl = route('storefront.product', $item['slug'] ?: $item['part_id']);
                    @endphp
                    <article class="sf-cart-item">
                        <a class="sf-cart-item__image" href="{{ $productUrl }}">
                            @if($item['image_url'])
                                <img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" loading="lazy">
                            @else
                                <span>GPSwiss<br>brak zdjęcia</span>
                            @endif
                        </a>
                        <div class="sf-cart-item__main">
                            <a class="sf-cart-item__title" href="{{ $productUrl }}">{{ $item['name'] }}</a>
                            <span>Numer części / SKU: <strong>{{ $item['sku'] ?: '—' }}</strong></span>
                            @unless($item['is_available'])
                                <em>Produkt wymaga ponownej weryfikacji dostępności.</em>
                            @endunless
                        </div>
                        <div class="sf-cart-item__price">{{ number_format((float) $item['unit_price'], 2, ',', ' ') }} {{ $item['currency'] }}</div>
                        <label class="sf-cart-item__qty">Ilość
                            <input type="number" name="quantities[{{ $item['part_id'] }}]" value="{{ $item['quantity'] }}" min="1" max="{{ max(1, (int) $item['current_quantity']) }}" step="1">
                        </label>
                        <div class="sf-cart-item__total">{{ number_format((float) $item['line_total'], 2, ',', ' ') }} {{ $item['currency'] }}</div>
                        <button class="sf-cart-item__remove" type="submit" form="remove-cart-item-{{ $item['part_id'] }}">Usuń</button>
                    </article>
                @endforeach
                <button class="sf-btn" type="submit">Aktualizuj koszyk</button>
            </form>

            <aside class="sf-cart-summary">
                <h2>Podsumowanie</h2>
                <div><span>Subtotal</span><strong>{{ number_format((float) $subtotal, 2, ',', ' ') }} {{ $items->first()['currency'] ?? 'PLN' }}</strong></div>
                <a class="sf-btn sf-btn--disabled" aria-disabled="true" href="#">Przejdź do zamówienia</a>
                <form method="post" action="{{ route('storefront.cart.clear') }}">
                    @csrf
                    <button class="sf-btn sf-btn--outline" type="submit">Wyczyść koszyk</button>
                </form>
            </aside>
        </div>

        @foreach($items as $item)
            <form id="remove-cart-item-{{ $item['part_id'] }}" method="post" action="{{ route('storefront.cart.remove', $item['part_id']) }}">
                @csrf
            </form>
        @endforeach
    @endif
</div>
@endsection

@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page sf-fr-cart">
    <div class="sf-cart-head"><h1>{{ __('storefront.cart') }}</h1><a class="sf-btn sf-btn--outline" href="{{ route('storefront.catalog') }}">{{ __('storefront.continue_shopping') }}</a></div>
    @if(!$draft['items'])
        <section class="sf-cart-empty"><h2>{{ __('storefront.empty_cart_title') }}</h2><p>{{ __('storefront.empty_cart_text') }}</p><a class="sf-btn" href="{{ route('storefront.catalog') }}">{{ __('storefront.go_to_shop') }}</a></section>
    @else
        @unless($draft['single_item'])<p class="sf-flash sf-flash--warning" role="alert">{{ __('storefront.fr_single_item_only') }}</p>@endunless
        <div class="sf-cart-layout">
            <form method="post" action="{{ route('storefront.cart.update') }}" class="sf-cart-items" aria-label="{{ __('storefront.cart_items') }}">
                @csrf
                @foreach($draft['items'] as $item)
                    <article class="sf-cart-item">
                        <a class="sf-cart-item__image" href="{{ $item['product_url'] }}">
                            @if($item['image_url'])<img src="{{ $item['image_url'] }}" alt="{{ $item['product_name'] }}" loading="lazy">@else<span>GPSwiss<br>{{ __('storefront.no_image') }}</span>@endif
                        </a>
                        <div class="sf-cart-item__main"><a class="sf-cart-item__title" href="{{ $item['product_url'] }}">{{ $item['product_name'] }}</a>@unless(($item['source_type'] ?? '') === 'jarek_gearbox')<span>{{ __('storefront.part_number_sku') }} : <strong>{{ $item['part_number'] ?: $item['sku'] ?: '—' }}</strong></span>@endunless@unless($item['is_available'])<em>{{ __('storefront.availability_recheck') }}</em>@endunless</div>
                        <div class="sf-cart-item__price">{{ $item['unit_price_cents'] === null ? __('storefront.fr_price_unavailable') : number_format($item['unit_price_cents'] / 100, 2, ',', ' ').' EUR' }}</div>
                        <label class="sf-cart-item__qty">{{ __('storefront.quantity') }}<input type="number" name="quantities[{{ $item['cart_key'] ?? $item['part_id'] }}]" value="{{ $item['quantity'] }}" min="1" max="1" step="1" required></label>
                        <div class="sf-cart-item__total">{{ $item['line_total_cents'] === null ? __('storefront.fr_price_unavailable') : number_format($item['line_total_cents'] / 100, 2, ',', ' ').' EUR' }}</div>
                        <button class="sf-cart-item__remove" type="submit" form="remove-fr-item-{{ $item['cart_key'] ?? $item['part_id'] }}">{{ __('storefront.remove') }}</button>
                    </article>
                @endforeach
                <button class="sf-btn" type="submit">{{ __('storefront.update_cart') }}</button>
            </form>
            <aside class="sf-cart-summary">
                @include('storefront.fr.order-summary')
                @if($draft['prices_ready'] && $draft['shipping_ready'] && $draft['single_item'])
                    <a class="sf-btn" href="{{ route('storefront.fr.checkout.show') }}">{{ __('storefront.checkout_go') }}</a>
                @else
                    <p>Une seule pièce, en quantité un, avec un prix et une livraison disponibles est nécessaire.</p><button class="sf-btn sf-btn--disabled" type="button" disabled>{{ __('storefront.checkout_go') }}</button>
                @endif
                <form method="post" action="{{ route('storefront.cart.clear') }}">@csrf<button class="sf-btn sf-btn--outline" type="submit">{{ __('storefront.clear_cart') }}</button></form>
            </aside>
        </div>
        @foreach($draft['items'] as $item)
            <form id="remove-fr-item-{{ $item['cart_key'] ?? $item['part_id'] }}" method="post" action="{{ ($item['source_type'] ?? '') === 'jarek_gearbox' ? route('storefront.jarek.remove', $item['jarek_fr_product_id']) : route('storefront.cart.remove', $item['part_id']) }}">@csrf</form>
        @endforeach
    @endif
</div>
@endsection

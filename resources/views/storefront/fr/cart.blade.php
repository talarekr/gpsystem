@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page">
    <h1>{{ __('storefront.cart') }}</h1>
    @if(!$draft['items'])
        <p>{{ __('storefront.empty_cart_text') }}</p>
    @else
        <form method="post" action="{{ route('storefront.cart.update') }}" class="sf-cart-items">
            @csrf
            @foreach($draft['items'] as $item)
                <label class="sf-field">{{ $item['product_name'] }} — {{ __('storefront.quantity') }}
                    <input type="number" name="quantities[{{ $item['part_id'] }}]" value="{{ $item['quantity'] }}" min="1">
                </label>
                @if(!$item['is_available'])<p>{{ __('storefront.availability_recheck') }}</p>@endif
            @endforeach
            <button class="sf-btn" type="submit">{{ __('storefront.update_cart') }}</button>
        </form>
        @foreach($draft['items'] as $item)
            <form method="post" action="{{ route('storefront.cart.remove', $item['part_id']) }}">
                @csrf<button class="sf-btn sf-btn--outline" type="submit">{{ __('storefront.remove') }} — {{ $item['product_name'] }}</button>
            </form>
        @endforeach
    @endif
    @include('storefront.fr.order-summary')
    <a class="sf-btn" href="{{ route('storefront.fr.checkout.show') }}">Préparer mes coordonnées</a>
    <a class="sf-btn sf-btn--outline" href="{{ route('storefront.catalog') }}">{{ __('storefront.back_to_shop') }}</a>
    <form method="post" action="{{ route('storefront.cart.clear') }}">@csrf<button class="sf-btn sf-btn--outline" type="submit">{{ __('storefront.clear_cart') }}</button></form>
</div>
@endsection

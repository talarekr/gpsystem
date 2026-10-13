@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page">
    <section class="sf-cart-empty">
        <h1>Paiements temporairement indisponibles</h1>
        <p>Les paiements de la boutique française sont temporairement indisponibles. Vous pouvez continuer à consulter le catalogue et conserver votre panier.</p>
        <a class="sf-btn" href="{{ route(\App\Support\StorefrontContext::routeName(request(), 'cart.index')) }}">{{ __('storefront.back_to_cart') }}</a>
        <a class="sf-btn" href="{{ route('storefront.catalog') }}">{{ __('storefront.back_to_shop') }}</a>
    </section>
</div>
@endsection

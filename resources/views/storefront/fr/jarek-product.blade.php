@extends('layouts.storefront')
@section('content')
<div class="sf-container sf-page">
    <a href="{{ route('storefront.jarek.index') }}">← Boîtes de vitesses</a>
    <h1>{{ $product['title'] }}</h1><p>{{ $product['sku'] }}</p>
    <div class="sf-grid sf-grid--3">@foreach($product['images'] as $image)<a href="{{ $image }}"><img src="{{ $image }}" alt="{{ $product['title'] }}" style="max-width:100%;height:auto" loading="lazy"></a>@endforeach</div>
    <p class="sf-price">{{ $product['cents'] === null ? __('storefront.fr_price_unavailable') : number_format($product['cents']/100, 2, ',', ' ').' EUR' }}</p>
    <p>{{ __('storefront.fr_delivery_cost', ['amount' => '130,00']) }}</p><p>Disponible — quantité maximale : 1</p>
    <form method="post" action="{{ route('storefront.jarek.add', $product['id']) }}">@csrf<input type="hidden" name="quantity" value="1"><button class="sf-btn" type="submit" @disabled(!$product['can_buy'])>{{ __('storefront.add_to_cart') }}</button></form>
    <h2>Description</h2><div>{!! nl2br(e($product['description'])) !!}</div>
</div>
@endsection

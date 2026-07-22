@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-cart-empty">
        <h1>{{ __('storefront.order_thanks') }}</h1>
        <p>{{ __('storefront.order_number') }}: <strong>{{ $order->order_number }}</strong></p>
        <p>{{ __('storefront.status') }}: <strong>{{ \App\Models\Order::statusOptions()[$order->status] ?? $order->status }}</strong></p>
        <p>{{ __('storefront.customer') }}: {{ $order->customer_name }} — {{ $order->email }} — {{ $order->phone }}</p>
        <p>{{ __('storefront.address') }}: {{ $order->address_line1 }}, {{ $order->postal_code }} {{ $order->city }}, {{ $order->country }}</p>
        <p>{{ __('storefront.total') }}: <strong>{{ number_format((float) $order->total, 2, ',', ' ') }} {{ $order->currency }}</strong></p>
        <a class="sf-btn" href="{{ route('storefront.catalog') }}">{{ __('storefront.back_to_shop') }}</a>
    </section>
</div>
@endsection

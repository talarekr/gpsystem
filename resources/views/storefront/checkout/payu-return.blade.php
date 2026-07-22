@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-cart-empty">
        <h1>{{ __('storefront.thank_you') }}</h1>
        <p>{{ __('storefront.payu_return_msg') }}</p>
        @if($order)
            <p>{{ __('storefront.order_number') }}: <strong>{{ $order->order_number }}</strong></p>
            <p>{{ __('storefront.shop_payment_status') }}: <strong>{{ $order->payment_status ?: 'pending' }}</strong></p>
            @if($remoteStatus)<p>{{ __('storefront.payu_status') }}: <strong>{{ $remoteStatus }}</strong></p>@endif
        @endif
        <a class="sf-btn" href="{{ route('storefront.catalog') }}">{{ __('storefront.back_to_shop') }}</a>
    </section>
</div>
@endsection

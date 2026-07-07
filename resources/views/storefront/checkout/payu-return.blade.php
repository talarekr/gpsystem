@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-cart-empty">
        <h1>Dziękujemy</h1>
        <p>Wróciłeś z PayU. Oczekujemy na automatyczne potwierdzenie płatności.</p>
        @if($order)
            <p>Numer zamówienia: <strong>{{ $order->order_number }}</strong></p>
            <p>Status płatności w sklepie: <strong>{{ $order->payment_status ?: 'pending' }}</strong></p>
            @if($remoteStatus)<p>Status odczytany z PayU: <strong>{{ $remoteStatus }}</strong></p>@endif
        @endif
        <a class="sf-btn" href="{{ route('storefront.catalog') }}">Wróć do sklepu</a>
    </section>
</div>
@endsection

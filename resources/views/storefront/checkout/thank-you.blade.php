@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-cart-empty">
        <h1>Dziękujemy za zamówienie</h1>
        <p>Numer zamówienia: <strong>{{ $order->order_number }}</strong></p>
        <p>Status: <strong>{{ \App\Models\Order::statusOptions()[$order->status] ?? $order->status }}</strong></p>
        <p>Klient: {{ $order->customer_name }} — {{ $order->email }} — {{ $order->phone }}</p>
        <p>Adres: {{ $order->address_line1 }}, {{ $order->postal_code }} {{ $order->city }}, {{ $order->country }}</p>
        <p>Razem: <strong>{{ number_format((float) $order->total, 2, ',', ' ') }} {{ $order->currency }}</strong></p>
        <a class="sf-btn" href="{{ route('storefront.catalog') }}">Wróć do sklepu</a>
    </section>
</div>
@endsection

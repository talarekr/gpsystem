@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page">
    <h1>{{ $cancelled ? 'Paiement interrompu' : 'État du paiement' }}</h1>
    <p>Commande : {{ $order->order_number }}</p>
    @if($order->payment_status === 'paid')
        <p>Paiement confirmé.</p>
        @if(data_get($order->meta, 'stripe.mode') === 'test')<p>Paiement de test : aucune préparation ni modification du stock.</p>@endif
        @if($order->status === 'on_hold' && data_get($order->meta, 'stripe.mode') !== 'test')<p>Votre commande nécessite une vérification. Notre équipe vous contactera.</p>@endif
    @else
        <p>Le retour sur cette page ne confirme pas le paiement. Nous attendons la confirmation sécurisée.</p>
    @endif
    @if($retryUrl && $order->payment_status === 'pending')<a class="sf-btn" href="{{ $retryUrl }}">Reprendre ce paiement Stripe</a>@endif
    <a class="sf-btn" href="{{ route('storefront.fr.checkout.show') }}">Retour à la commande</a>
</div>
@endsection

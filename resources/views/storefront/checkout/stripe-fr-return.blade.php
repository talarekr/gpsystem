@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-section" lang="fr">
    <h1>Commande {{ $order->order_number }}</h1>
    @if($order->payment_status === 'paid')
        <p>Votre paiement a été confirmé. Merci pour votre commande.</p>
    @elseif($cancelled)
        <p>Le paiement n’a pas été finalisé. Votre commande reste enregistrée ; aucun paiement n’est confirmé à ce stade.</p>
    @elseif($order->payment_status === 'failed')
        <p>Le paiement n’a pas abouti. Veuillez nous contacter au sujet de votre commande.</p>
    @else
        <p>Votre commande a été enregistrée. Nous attendons la confirmation du paiement.</p>
    @endif
    <a class="sf-btn" href="https://gpswiss.fr">Retour à la boutique</a>
</div>
@endsection

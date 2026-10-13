@extends('layouts.storefront')
@section('content')
<div class="sf-container sf-page"><section class="sf-static-card">
<h1>Mes commandes GPSwiss.fr</h1>
<p>Seules les commandes passées sur GPSwiss.fr en étant connecté à ce compte sont affichées ici. Pour une commande passée sans connexion, utilisez le lien personnel reçu par e-mail.</p>
@forelse($orders as $order)
<article class="sf-order-card">
<strong>{{ $order->order_number }}</strong>
<span>{{ optional($order->ordered_at ?? $order->created_at)->format('d/m/Y') }}</span>
<span>Statut : {{ \App\Support\FrenchOrderPresentation::status($order->status) }}</span>
<span>Paiement : {{ \App\Support\FrenchOrderPresentation::payment($order->payment_status) }}</span>
<span>Total : {{ number_format((float) $order->total, 2, ',', ' ') }} {{ $order->currency }}</span>
<a href="{{ \App\Support\FrenchOrderPresentation::link($order) }}">Consulter ma commande</a>
</article>
@empty
<p>Aucune commande GPSwiss.fr n’est associée à ce compte.</p>
@endforelse
@if($orders->hasMorePages())<a href="{{ $orders->nextPageUrl() }}">Commandes plus anciennes</a>@endif
@if($orders->previousPageUrl())<a href="{{ $orders->previousPageUrl() }}">Commandes plus récentes</a>@endif
<p>Pour un retour ou une réclamation, contactez <a href="mailto:{{ config('storefront-contact.fr.email') }}">{{ config('storefront-contact.fr.email') }}</a> en indiquant le numéro de commande. Le formulaire de retour historique du site polonais n’est pas utilisé pour ces commandes.</p>
<form method="post" action="{{ route('storefront.logout') }}">@csrf<button class="sf-btn" type="submit">Se déconnecter</button></form>
</section></div>
@endsection

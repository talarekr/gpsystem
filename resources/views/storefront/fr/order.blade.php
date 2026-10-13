@extends('layouts.storefront')
@section('content')
<div class="sf-container sf-page"><section class="sf-static-card">
<h1>Votre commande</h1>
<p>Commande : <strong>{{ $order->order_number }}</strong></p>
<p>Statut : {{ \App\Support\FrenchOrderPresentation::status($order->status) }}</p>
<p>Paiement : {{ \App\Support\FrenchOrderPresentation::payment($order->payment_status) }}</p>
@if(data_get($order->meta, 'stripe.mode') === 'test')<p>Commande de test : aucune expédition ne sera déclenchée.</p>@endif
<p>Client : {{ $order->customer_name }} — {{ $order->email }}</p>
<p>Adresse de facturation : {{ $order->address_line1 }}, {{ $order->postal_code }} {{ $order->city }}, {{ $order->country }}</p>
@foreach($order->items as $item)
<p>{{ $item->product_name }} — {{ $item->quantity }} × {{ number_format((float) $item->unit_price, 2, ',', ' ') }} {{ $item->currency }}</p>
@endforeach
<p>Livraison : {{ number_format((float) $order->shipping_total, 2, ',', ' ') }} EUR</p>
<p>Total : <strong>{{ number_format((float) $order->total, 2, ',', ' ') }} {{ $order->currency }}</strong></p>
@if($order->status === 'new' && $order->payment_status === 'pending')
<p><a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('storefront.fr.stripe.return', now()->addDays(7), ['order' => $order->id]) }}">Consulter le paiement et reprendre la session si elle est encore disponible</a></p>
@endif
<p><a href="{{ route('storefront.contact') }}">Contacter GREGOR SWISS FR</a></p>
<a class="sf-btn" href="{{ route('storefront.catalog') }}">Retour à la boutique</a>
</section></div>
@endsection

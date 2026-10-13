<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Votre commande GPSwiss.fr</title></head>
<body>
<p>Bonjour {{ $order->customer_name }},</p>
@if($kind === 'paid')
    <h1>Paiement confirmé</h1>
    <p>Nous avons reçu la confirmation sécurisée du paiement de votre commande.</p>
    @if(data_get($order->meta, 'stripe.mode') === 'test')<p>Il s’agit d’un paiement de test. Aucune expédition ne sera déclenchée.</p>@endif
@elseif($kind === 'expired')
    <h1>Session de paiement expirée</h1>
    <p>La session de paiement a expiré sans confirmation de paiement. Votre commande a été annulée. Consultez son état avant de passer une nouvelle commande.</p>
@else
    <h1>Commande reçue — paiement en attente</h1>
    <p>Nous avons enregistré votre commande. Cette notification ne confirme pas le paiement. Vous recevrez une confirmation après validation sécurisée du paiement.</p>
@endif
<p>Commande : <strong>{{ $order->order_number }}</strong></p>
<table><thead><tr><th>Article</th><th>Quantité</th><th>Prix unitaire</th><th>Total</th></tr></thead><tbody>
@foreach($order->items as $item)
<tr><td>{{ $item->product_name }} ({{ $item->sku }})</td><td>{{ $item->quantity }}</td><td>{{ number_format((float) $item->unit_price, 2, ',', ' ') }} {{ $item->currency }}</td><td>{{ number_format((float) $item->line_total, 2, ',', ' ') }} {{ $item->currency }}</td></tr>
@endforeach
</tbody></table>
<p>Livraison : {{ number_format((float) $order->shipping_total, 2, ',', ' ') }} EUR<br>
Total : <strong>{{ number_format((float) $order->total, 2, ',', ' ') }} EUR</strong></p>
<p><a href="{{ $orderUrl }}">Consulter votre commande et son paiement</a></p>
<p>Ce lien personnel est valable 30 jours. Ne le partagez pas. Après expiration, contactez-nous pour obtenir de l’aide.</p>
<hr>
@include('storefront.fr.company-identity')
</body></html>

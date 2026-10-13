<section class="sf-checkout-card">
    <h2>Récapitulatif en EUR</h2>
    @if(!$draft['prices_ready'])<p>Les prix en EUR ne sont pas encore disponibles pour ce panier. Aucun montant en PLN n’est converti automatiquement.</p>@endif
    @foreach($draft['items'] as $item)
        <p><a href="{{ $item['product_url'] }}">{{ $item['product_name'] }}</a> × {{ $item['quantity'] }}<br>
        {{ $item['sku'] }} — {{ $item['line_total_cents'] === null ? 'Prix EUR indisponible' : number_format($item['line_total_cents'] / 100, 2, ',', ' ').' EUR' }}</p>
    @endforeach
    <p>Sous-total : {{ $draft['subtotal_cents'] === null ? 'Indisponible' : number_format($draft['subtotal_cents'] / 100, 2, ',', ' ').' EUR' }}</p>
    <p>Livraison par transporteur en France : {{ $draft['shipping_cents'] === null ? 'Tarif non disponible — commande indisponible' : number_format($draft['shipping_cents'] / 100, 2, ',', ' ').' EUR' }}</p>
    <p>Total : {{ $draft['total_cents'] === null ? 'Indisponible' : number_format($draft['total_cents'] / 100, 2, ',', ' ').' EUR' }}</p>
    <p><strong>Paiements temporairement indisponibles.</strong> Ce récapitulatif ne constitue pas une commande.</p>
</section>

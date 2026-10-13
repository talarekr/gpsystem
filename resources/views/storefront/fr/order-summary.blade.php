<h2>Résumé</h2>
@if(!$draft['single_item'])
<p role="alert">{{ $draft['items'] ? __('storefront.fr_single_item_only') : __('storefront.empty_cart_title') }}</p>
@else
<div class="sf-checkout-summary__items">
    @foreach($draft['items'] as $item)
        <div class="sf-checkout-summary__row sf-checkout-summary__row--product"><a class="sf-checkout-summary__product-title" href="{{ $item['product_url'] }}">{{ $item['product_name'] }} <em>× {{ $item['quantity'] }}</em></a><strong>{{ $item['line_total_cents'] === null ? __('storefront.fr_price_unavailable') : number_format($item['line_total_cents'] / 100, 2, ',', ' ').' EUR' }}</strong></div>
    @endforeach
</div>
<div class="sf-checkout-summary__row"><span>Sous-total</span><strong>{{ $draft['subtotal_cents'] === null ? 'Indisponible' : number_format($draft['subtotal_cents'] / 100, 2, ',', ' ').' EUR' }}</strong></div>
<div class="sf-checkout-summary__row"><span>Livraison</span><strong>{{ $draft['shipping_cents'] === null ? 'Livraison indisponible pour cet article' : number_format($draft['shipping_cents'] / 100, 2, ',', ' ').' EUR' }}</strong></div>
<div class="sf-checkout-summary__row sf-checkout-summary__row--total"><span>Total</span><strong>{{ $draft['total_cents'] === null ? 'Indisponible' : number_format($draft['total_cents'] / 100, 2, ',', ' ').' EUR' }}</strong></div>
@if(!$draft['prices_ready'])<p>{{ __('storefront.fr_price_unavailable') }}</p>@endif

@endif

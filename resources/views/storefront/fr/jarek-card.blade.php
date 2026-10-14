@php($p = app(\App\Services\Storefront\JarekFrPresentation::class)->forProduct($jarek))
<article class="sf-product-card">
    <a class="sf-product-card__image" href="{{ $p['url'] }}">@if($p['images'])<img src="{{ $p['images'][0] }}" alt="{{ $p['title'] }}" loading="lazy">@endif</a>
    <div class="sf-product-card__body"><div class="sf-part-number">{{ $p['sku'] }}</div><a class="sf-product-title" href="{{ $p['url'] }}">{{ $p['title'] }}</a>
    <div class="sf-price">{{ $p['cents'] === null ? __('storefront.fr_price_unavailable') : number_format($p['cents']/100, 2, ',', ' ').' EUR' }}</div>
    <div class="sf-delivery">{{ __('storefront.fr_delivery_cost', ['amount' => '130,00']) }}</div></div>
</article>

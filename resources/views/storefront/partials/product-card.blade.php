@php
    $part ??= null;
    $isJarekProduct = $part instanceof \App\Services\Storefront\JarekCatalogProduct;
    $productUrl = '#';
    $image = null;
    $src = null;

    if ($part) {
        try {
            $productUrl = $isJarekProduct ? $part->presentation['url'] : route('storefront.product', $part->slug ?: $part->id);
        } catch (Throwable $exception) {
            $productUrl = url('/czesci');
        }

        try {
            $image = $part->listingImage();
            $src = $part->listingImageUrl();
        } catch (Throwable $exception) {
            $image = null;
            $src = null;
        }
    }

    $name = trim((string) ($part?->storefrontNameForLocale($storefrontContentLocale) ?? __('storefront.default_part_name'))) ?: __('storefront.default_part_name');
    $number = $part?->part_number ?: $part?->sku ?: '—';
    $currency = $part?->currency ?: 'PLN';
    $price = $part?->price;
    $isFrenchProduct = \App\Support\StorefrontContext::isFrench(request());
    $frProduct = $isFrenchProduct ? ($isJarekProduct ? $part->presentation : app(\App\Services\Storefront\FrenchProductPresentation::class)->forPart($part)) : null;
    if ($isFrenchProduct) {
        $currency = 'EUR';
        $price = $frProduct['cents'] === null ? null : $frProduct['cents'] / 100;
    }
@endphp
@if($part)
<article class="sf-product-card">
    <a class="sf-product-card__image" href="{{ $productUrl }}">
        @if($src)
            <img src="{{ $src }}" alt="{{ $image?->alt_text ?: $name }}" loading="lazy">
        @else
            <span>GPSwiss<br>{{ __('storefront.no_image') }}</span>
        @endif
    </a>
    <button class="sf-heart" type="button" aria-label="{{ __('storefront.add_favorite') }}">♡</button>
    <div class="sf-product-card__body"><div class="sf-part-number">{{ __('storefront.part_number') }} <strong>{{ $number }}</strong></div><a class="sf-product-title" href="{{ $productUrl }}">{{ $name }}</a><div class="sf-price">@if($price !== null){{ number_format((float) $price, 2, ',', ' ') }} {{ $currency }}@else {{ $isFrenchProduct ? __('storefront.fr_price_unavailable') : __('storefront.price_on_request') }} @endif</div><div class="sf-delivery">{{ $isFrenchProduct ? ($frProduct['delivery']['available'] ? __('storefront.fr_delivery_cost', ['amount' => number_format($frProduct['delivery']['amount_cents'] / 100, 2, ',', ' ')]) : __('storefront.fr_delivery_unavailable')) : __('storefront.free_delivery') }}</div>@unless(\App\Support\StorefrontContext::isFrench(request()))<div class="sf-cutoff">{{ __('storefront.payment_cutoff') }}</div>@endunless</div>
</article>
@endif

@php
    $part ??= null;
    $productUrl = '#';
    $image = null;
    $src = null;

    if ($part) {
        try {
            $productUrl = route('storefront.product', $part->slug ?: $part->id);
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

    $name = trim((string) ($part?->name ?? __('storefront.default_part_name'))) ?: __('storefront.default_part_name');
    $number = $part?->part_number ?: $part?->sku ?: '—';
    $currency = $part?->currency ?: 'PLN';
    $price = $part?->price;
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
    <div class="sf-product-card__body"><div class="sf-part-number">{{ __('storefront.part_number') }} <strong>{{ $number }}</strong></div><a class="sf-product-title" href="{{ $productUrl }}">{{ $name }}</a><div class="sf-price">@if($price !== null){{ number_format((float) $price, 2, ',', ' ') }} {{ $currency }}@else {{ __('storefront.price_on_request') }} @endif</div><div class="sf-delivery">{{ __('storefront.free_delivery') }}</div><div class="sf-cutoff">{{ __('storefront.payment_cutoff') }}</div></div>
</article>
@endif

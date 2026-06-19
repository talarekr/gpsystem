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

    $name = trim((string) ($part?->name ?? 'Część samochodowa')) ?: 'Część samochodowa';
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
            <span>GPSwiss<br>brak zdjęcia</span>
        @endif
    </a>
    <button class="sf-heart" type="button" aria-label="Dodaj do ulubionych">♡</button>
    <div class="sf-product-card__body"><div class="sf-part-number">Numer części <strong>{{ $number }}</strong></div><a class="sf-product-title" href="{{ $productUrl }}">{{ $name }}</a><div class="sf-price">@if($price !== null){{ number_format((float) $price, 2, ',', ' ') }} {{ $currency }}@else Cena na zapytanie @endif</div><div class="sf-delivery">Darmowa dostawa: najbliższy dzień roboczy</div><div class="sf-cutoff">Jeśli zapłacisz do 13:30</div></div>
</article>
@endif

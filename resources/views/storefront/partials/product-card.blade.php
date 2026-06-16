@php
    $productUrl = route('storefront.product', $part->slug ?: $part->id);
    $image = $part->primaryImage();
    $src = $image?->publicUrl();
@endphp
<article class="sf-product-card">
    <a class="sf-product-card__image" href="{{ $productUrl }}">
        @if($src)
            <img src="{{ $src }}" alt="{{ $image->alt_text ?: $part->name }}" loading="lazy">
        @else
            <span>GPSwiss<br>brak zdjęcia</span>
        @endif
    </a>
    <button class="sf-heart" type="button" aria-label="Dodaj do ulubionych">♡</button>
    <div class="sf-product-card__body"><div class="sf-part-number">Numer części <strong>{{ $part->part_number ?: $part->sku ?: '—' }}</strong></div><a class="sf-product-title" href="{{ $productUrl }}">{{ $part->name }}</a><div class="sf-price">{{ number_format((float) $part->price, 2, ',', ' ') }} {{ $part->currency ?: 'PLN' }}</div><div class="sf-delivery">Darmowa dostawa: najbliższy dzień roboczy</div><div class="sf-cutoff">Jeśli zapłacisz do 13:30</div></div>
</article>

@extends('layouts.storefront')
@section('content')
@php
    $mainImage = $part->primaryImage();
    $mainSrc = $mainImage?->productUrl();
    $description = $part->storefrontDescription();
    $details = $part->storefrontDetails();
    $galleryImages = $part->images
        ->sortBy([
            ['is_primary', 'desc'],
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ])
        ->map(function ($image) use ($part) {
            $productSrc = $image->productUrl();
            $thumbSrc = $image->listingUrl() ?: $image->publicUrl();

            if (! $productSrc) {
                return null;
            }

            return [
                'product' => $productSrc,
                'thumb' => $thumbSrc ?: $productSrc,
                'alt' => $image->alt_text ?: $part->name,
            ];
        })
        ->filter()
        ->values();
@endphp
<div class="sf-container sf-page gp-product-page" id="product-{{ $part->id }}">
    @include('storefront.partials.breadcrumbs')
    <div class="sf-product-detail">
        <section class="sf-gallery" data-product-gallery>
            <div class="sf-gallery__main">
                @if($mainSrc)
                    <button class="sf-gallery__main-button" type="button" data-gallery-open aria-label="Powiększ zdjęcie produktu">
                        <img src="{{ $mainSrc }}" alt="{{ $mainImage->alt_text ?: $part->name }}" data-gallery-main>
                    </button>
                    @if($galleryImages->count() > 1)
                        <button class="sf-gallery__nav sf-gallery__nav--prev" type="button" data-gallery-main-prev aria-label="Poprzednie zdjęcie">‹</button>
                        <button class="sf-gallery__nav sf-gallery__nav--next" type="button" data-gallery-main-next aria-label="Następne zdjęcie">›</button>
                    @endif
                @else
                    <span>GPSwiss<br>brak zdjęcia</span>
                @endif
            </div>
            @if($galleryImages->count() > 1)
                <div class="sf-thumbs" aria-label="Miniatury zdjęć produktu">
                    @foreach($galleryImages as $index => $image)
                        <button class="sf-thumbs__item{{ $index === 0 ? ' is-active' : '' }}" type="button" data-gallery-thumb data-index="{{ $index }}" data-product-src="{{ $image['product'] }}" data-thumb-src="{{ $image['thumb'] }}" data-alt="{{ $image['alt'] }}" aria-label="Pokaż zdjęcie {{ $index + 1 }}" aria-current="{{ $index === 0 ? 'true' : 'false' }}">
                            <img src="{{ $image['thumb'] }}" alt="{{ $image['alt'] }}" loading="lazy">
                        </button>
                    @endforeach
                </div>
            @endif
            @if($galleryImages->isNotEmpty())
                <div class="sf-lightbox" data-gallery-lightbox hidden aria-hidden="true">
                    <div class="sf-lightbox__backdrop" data-gallery-close></div>
                    <div class="sf-lightbox__dialog" role="dialog" aria-modal="true" aria-label="Powiększone zdjęcie produktu">
                        <button class="sf-lightbox__close" type="button" data-gallery-close aria-label="Zamknij powiększenie">×</button>
                        @if($galleryImages->count() > 1)
                            <button class="sf-lightbox__nav sf-lightbox__nav--prev" type="button" data-gallery-prev aria-label="Poprzednie zdjęcie">‹</button>
                            <button class="sf-lightbox__nav sf-lightbox__nav--next" type="button" data-gallery-next aria-label="Następne zdjęcie">›</button>
                        @endif
                        <img class="sf-lightbox__image" src="{{ $mainSrc ?: $galleryImages->first()['product'] }}" alt="{{ $mainImage?->alt_text ?: $part->name }}" data-gallery-lightbox-image>
                    </div>
                </div>
            @endif
        </section>
        <section class="sf-info-card"><h1>{{ $part->name }}</h1><p><strong>Numer części:</strong> {{ $part->part_number ?: $part->sku ?: '—' }}</p><p><strong>Stan:</strong> Używany / sprawdzony</p>@if($part->car_id)<a class="sf-link-box" href="{{ route('storefront.catalog', ['vehicle_model'=>trim(($part->car?->make ?? '').' '.($part->car?->model ?? ''))]) }}">Pokaż więcej części z tego pojazdu</a>@endif@if($part->short_description)<div>{!! nl2br(e($part->short_description)) !!}</div>@endif<div class="sf-trust"><span>Czas dostawy</span><span>Metody płatności</span><span>Zwrot do 14 dni zgodnie z regulaminem</span></div></section><aside class="sf-purchase"><span>Cena produktu</span><strong>{{ number_format((float)$part->price,2,',',' ') }} {{ $part->currency ?: 'PLN' }}</strong><p>Cena brutto. Najniższa cena z 30 dni dostępna przy finalizacji zamówienia.</p><button disabled>Dodaj do koszyka</button><a href="mailto:biuro@gpswiss.pl">Masz pytanie? Skontaktuj się</a><small>Pomagamy w doborze części po numerze VIN / OEM.</small></aside></div><div class="sf-tabs"><section class="sf-details-section"><p class="sf-details-description">{!! nl2br(e($description)) !!}</p><h2>Informacje szczegółowe</h2>@if($details)<div class="sf-details-table">@foreach($details as $detail)<div class="sf-details-row"><div class="sf-details-label">{{ $detail['label'] }}</div><div class="sf-details-value">{{ $detail['value'] }}</div></div>@endforeach</div>@else<p>Szczegółowe informacje zostaną uzupełnione.</p>@endif</section></div>@if($related->isNotEmpty())<section class="sf-section"><div class="sf-section__head"><h2>Więcej części z tego pojazdu</h2></div><div class="sf-grid sf-grid--4">@foreach($related as $part) @include('storefront.partials.product-card', ['part'=>$part]) @endforeach</div></section>@endif</div>
@endsection

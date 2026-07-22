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
            <div class="sf-gallery__layout">
                @if($galleryImages->count() > 1)
                    <div class="sf-thumbs-shell{{ $galleryImages->count() > 5 ? ' sf-thumbs-shell--scrollable' : '' }}" aria-label="{{ __('storefront.gallery_thumbs') }}">
                        @if($galleryImages->count() > 5)
                            <button class="sf-thumbs-shell__arrow sf-thumbs-shell__arrow--prev" type="button" data-gallery-thumbs-prev aria-label="{{ __('storefront.thumbs_up') }}">⌃</button>
                        @endif
                        <div class="sf-thumbs" data-gallery-thumbs-track>
                            @foreach($galleryImages as $index => $image)
                                <button class="sf-thumbs__item{{ $index === 0 ? ' is-active' : '' }}" type="button" data-gallery-thumb data-index="{{ $index }}" data-product-src="{{ $image['product'] }}" data-thumb-src="{{ $image['thumb'] }}" data-alt="{{ $image['alt'] }}" aria-label="{{ __('storefront.show_photo', ['number' => $index + 1]) }}" aria-current="{{ $index === 0 ? 'true' : 'false' }}">
                                    <img src="{{ $image['thumb'] }}" alt="{{ $image['alt'] }}" loading="lazy">
                                </button>
                            @endforeach
                        </div>
                        @if($galleryImages->count() > 5)
                            <button class="sf-thumbs-shell__arrow sf-thumbs-shell__arrow--next" type="button" data-gallery-thumbs-next aria-label="{{ __('storefront.thumbs_down') }}">⌄</button>
                        @endif
                    </div>
                @endif
                <div class="sf-gallery__main">
                    @if($mainSrc)
                        <button class="sf-gallery__main-button" type="button" data-gallery-open aria-label="{{ __('storefront.zoom_product_photo') }}">
                            <img src="{{ $mainSrc }}" alt="{{ $mainImage->alt_text ?: $part->name }}" data-gallery-main>
                        </button>
                        @if($galleryImages->count() > 1)
                            <button class="sf-gallery__nav sf-gallery__nav--prev" type="button" data-gallery-main-prev aria-label="{{ __('storefront.previous_photo') }}">‹</button>
                            <button class="sf-gallery__nav sf-gallery__nav--next" type="button" data-gallery-main-next aria-label="{{ __('storefront.next_photo') }}">›</button>
                        @endif
                    @else
                        <span>GPSwiss<br>{{ __('storefront.no_image') }}</span>
                    @endif
                </div>
            </div>
            @if($galleryImages->isNotEmpty())
                <div class="sf-lightbox" data-gallery-lightbox hidden aria-hidden="true">
                    <div class="sf-lightbox__backdrop" data-gallery-close></div>
                    <div class="sf-lightbox__dialog" role="dialog" aria-modal="true" aria-label="{{ __('storefront.zoomed_product_photo') }}">
                        <button class="sf-lightbox__close" type="button" data-gallery-close aria-label="{{ __('storefront.close_zoom') }}">×</button>
                        @if($galleryImages->count() > 1)
                            <button class="sf-lightbox__nav sf-lightbox__nav--prev" type="button" data-gallery-prev aria-label="{{ __('storefront.previous_photo') }}">‹</button>
                            <button class="sf-lightbox__nav sf-lightbox__nav--next" type="button" data-gallery-next aria-label="{{ __('storefront.next_photo') }}">›</button>
                        @endif
                        <img class="sf-lightbox__image" src="{{ $mainSrc ?: $galleryImages->first()['product'] }}" alt="{{ $mainImage?->alt_text ?: $part->name }}" data-gallery-lightbox-image>
                    </div>
                </div>
            @endif
        </section>
        <section class="sf-info-card"><h1>{{ $part->name }}</h1><p><strong>{{ __('storefront.part_number') }}:</strong> {{ $part->part_number ?: $part->sku ?: '—' }}</p><p><strong>{{ __('storefront.condition') }}:</strong> {{ __('storefront.used_checked') }}</p>@if($part->car_id)<a class="sf-link-box" href="{{ route('storefront.catalog', ['vehicle_model'=>trim(($part->car?->make ?? '').' '.($part->car?->model ?? ''))]) }}">{{ __('storefront.show_more_vehicle') }}</a>@endif<div class="sf-trust"><span>{{ __('storefront.delivery_time') }}</span><span>{{ __('storefront.payment_methods') }}</span><span>{{ __('storefront.return_14_days') }}</span></div></section><aside class="sf-purchase"><span>{{ __('storefront.product_price') }}</span><strong>{{ number_format((float)$part->price,2,',',' ') }} {{ $part->currency ?: 'PLN' }}</strong><p>{{ __('storefront.gross_price_note') }}</p>@if((int) $part->quantity > 0 && ! in_array($part->status, ['sold', 'archived'], true))<form method="post" action="{{ route('storefront.cart.add', $part) }}">@csrf<button class="sf-purchase__cart" type="submit">{{ __('storefront.add_to_cart') }}</button></form>@else<button disabled>{{ __('storefront.unavailable') }}</button><small>{{ __('storefront.out_of_stock') }}</small>@endif<a href="mailto:biuro@gpswiss.pl">{{ __('storefront.have_question') }}</a><small>{{ __('storefront.vin_oem_help') }}</small></aside></div><div class="sf-tabs"><section class="sf-details-section"><p class="sf-details-description">{!! nl2br(e($description)) !!}</p><h2>{{ __('storefront.details') }}</h2>@if($details)<div class="sf-details-table">@foreach($details as $detail)<div class="sf-details-row"><div class="sf-details-label">{{ $detail['label'] }}</div><div class="sf-details-value">{{ $detail['value'] }}</div></div>@endforeach</div>@else<p>{{ __('storefront.details_pending') }}</p>@endif</section></div>@if($related->isNotEmpty())<section class="sf-section"><div class="sf-section__head"><h2>{{ __('storefront.more_vehicle_parts') }}</h2></div><div class="sf-grid sf-grid--4">@foreach($related as $part) @include('storefront.partials.product-card', ['part'=>$part]) @endforeach</div></section>@endif</div>
@endsection

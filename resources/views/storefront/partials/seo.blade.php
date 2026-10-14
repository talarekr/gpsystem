@php
    $storefrontSeo = app(\App\Services\Storefront\StorefrontSeo::class)->metadata(request(), ($part ?? null) instanceof \App\Models\Part ? $part : null, $category ?? null);
@endphp
<link rel="canonical" href="{{ $storefrontSeo['canonical'] }}">
@if($storefrontSeo['robots'])<meta name="robots" content="{{ $storefrontSeo['robots'] }}">@endif
@foreach($storefrontSeo['alternates'] as $language => $alternateUrl)
<link rel="alternate" hreflang="{{ $language }}" href="{{ $alternateUrl }}">
@endforeach

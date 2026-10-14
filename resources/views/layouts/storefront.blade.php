<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $metaTitle ?? __('storefront.default_title') }}</title>
    <meta name="description" content="{{ $metaDescription ?? __('storefront.default_desc') }}">
    @include('storefront.partials.seo')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/storefront.css') }}?v={{ filemtime(public_path('css/storefront.css')) }}">
</head>
<body>
@include('storefront.partials.header')
<main>
    @include('storefront.partials.flash')
    @yield('content')
</main>
@include('storefront.partials.footer')
<script>
    window.GPSwiss = window.GPSwiss || {};
    @php
        $storefrontI18n = __('storefront');
        if (\App\Support\StorefrontContext::isFrench(request())) {
            // FR does not use the legacy PL shipping copy or the removed product price note.
            foreach (['courier_free', 'courier_cod_free', 'pickup_free', 'free_delivery', 'payment_cutoff', 'gross_price_note'] as $key) {
                unset($storefrontI18n[$key]);
            }
        }
    @endphp
    window.GPSwiss.i18n = @json($storefrontI18n);
</script>
<script src="{{ asset('js/storefront-category-menu.js') }}" defer></script>
<script src="{{ asset('js/storefront-product-gallery.js') }}" defer></script>
<script src="{{ asset('js/storefront-product-carousel.js') }}" defer></script>
</body>
</html>


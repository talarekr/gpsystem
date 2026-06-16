<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $metaTitle ?? 'GPSwiss - części samochodowe' }}</title>
    <meta name="description" content="{{ $metaDescription ?? 'Oryginalne używane części samochodowe GPSwiss.' }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/storefront.css') }}">
</head>
<body>
@include('storefront.partials.header')
<main>@yield('content')</main>
@include('storefront.partials.footer')
<script src="{{ asset('js/storefront-category-menu.js') }}" defer></script>
</body>
</html>

<div class="sf-storefront-header" aria-label="{{ __('storefront.header_label') }}">
<header class="sf-top">
    <div class="sf-container sf-top__inner">
        <div class="sf-top__links">
            <a href="{{ route('storefront.contact') }}">{{ __('storefront.contact') }}</a>
            <a href="{{ route('storefront.terms') }}">{{ __('storefront.terms') }}</a>
        </div>
        <form method="post" action="{{ route('storefront.locale') }}" class="sf-language" aria-label="{{ __('storefront.language_selector') }}">
            @csrf
            <select class="sf-language-select" name="locale" onchange="this.form.submit()">
                @foreach(['pl','de','en','fr','uk'] as $locale)
                    <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ __('storefront.language_names.'.$locale) }}</option>
                @endforeach
            </select>
        </form>
    </div>
</header>
<div class="sf-container sf-main-row">
    @php
        $storefrontLogoPath = '/storage/brand/logo.png';
        $storefrontLogoPublicHtmlPath = dirname(base_path()) . '/public_html/storage/brand/logo.png';
        $storefrontLogoExists = file_exists($storefrontLogoPublicHtmlPath) || file_exists(public_path('storage/brand/logo.png'));
    @endphp
    <a class="sf-logo" href="{{ route('storefront.home') }}" aria-label="GP Swiss - {{ __('storefront.home') }}">
        @if($storefrontLogoExists)
            <img src="{{ $storefrontLogoPath }}" alt="GP Swiss" style="display:block;height:clamp(38px,8vw,50px);width:auto;max-width:100%;object-fit:contain;">
        @else
            <span>GP</span>Swiss
        @endif
    </a>
    @include('storefront.partials.search-bar')
    <details class="sf-profile">
        <summary aria-label="{{ __('storefront.account') }}"><span aria-hidden="true">👤</span> <span class="sf-profile__label">{{ __('storefront.account') }}</span></summary>
        <div>
            @php $storefrontCustomer = auth()->user(); @endphp
            @if($storefrontCustomer)
                <a href="{{ route('storefront.account') }}">{{ __('storefront.customer_panel') }}</a>
                <a href="{{ route('storefront.account') }}#orders">{{ __('storefront.orders') }}</a>
                <form method="post" action="{{ route('storefront.logout') }}">@csrf<button type="submit">{{ __('storefront.logout') }}</button></form>
            @else
                <a href="{{ route('storefront.login') }}">{{ __('storefront.login') }}</a>
                <a href="{{ route('storefront.register') }}">{{ __('storefront.register') }}</a>
            @endif
        </div>
    </details>
    <a class="sf-cart sf-cart--icon" href="{{ route('storefront.cart.index') }}" aria-label="{{ __('storefront.cart') }}"><span aria-hidden="true">🛒</span><b>{{ $storefrontCartCount ?? 0 }}</b></a>
</div>
@php
    $storefrontMainLinks = [
        __('storefront.engines') => '/kategoria-produktu/silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki',
        __('storefront.gearbox') => '/kategoria-produktu/uklad-napedowy/skrzynie-biegow-i-inne-elementy/automatyczna-skrzynia-biegow',
        __('storefront.dpf') => '/kategoria-produktu/uklad-wydechowy-i-inne-elementy/elementy-systemu-kontroli-spalin/filtr-czastek-stalych-katalizator-fap-dpf',
        __('storefront.knuckles') => '/kategoria-produktu/os-przednia-i-inne-elementy/os-przednia/zwrotnica-kola-przedniego',
    ];
@endphp
<nav class="sf-nav"><div class="sf-container sf-nav__inner">
    @include('storefront.partials.category-menu')
    <div class="sf-nav__links" aria-label="{{ __('storefront.main_menu') }}">
        @foreach($storefrontMainLinks as $label => $url)<a href="{{ $url }}">{{ $label }}</a>@endforeach
    </div>
    <a class="sf-phones sf-phones--direct" href="tel:+48504266984" aria-label="{{ __('storefront.call_number', ['number' => '+48 504 266 984']) }}"><span aria-hidden="true">📞</span> <span class="sf-phones__number">+48 504 266 984</span></a>
</div></nav>
</div>

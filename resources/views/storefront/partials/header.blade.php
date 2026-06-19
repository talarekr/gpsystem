<header class="sf-top">
    <div class="sf-container sf-top__inner">
        <div class="sf-top__links">
            <a href="{{ route('storefront.contact') }}">Kontakt</a>
            <a href="{{ route('storefront.terms') }}">Regulamin</a>
        </div>
        <label class="sf-language" aria-label="Wybór języka strony">
            <select class="sf-language-select" name="language">
                <option selected>Polski</option>
                <option>Angielski</option>
                <option>Niemiecki</option>
                <option>Francuski</option>
                <option>Ukraiński</option>
            </select>
        </label>
    </div>
</header>
<div class="sf-container sf-main-row">
    @php
        $storefrontLogoPath = '/storage/brand/logo.png';
        $storefrontLogoPublicHtmlPath = dirname(base_path()) . '/public_html/storage/brand/logo.png';
        $storefrontLogoExists = file_exists($storefrontLogoPublicHtmlPath) || file_exists(public_path('storage/brand/logo.png'));
    @endphp
    <a class="sf-logo" href="{{ route('storefront.home') }}" aria-label="GP Swiss - strona główna">
        @if($storefrontLogoExists)
            <img src="{{ $storefrontLogoPath }}" alt="GP Swiss" style="display:block;height:clamp(38px,8vw,50px);width:auto;max-width:100%;object-fit:contain;">
        @else
            <span>GP</span>Swiss
        @endif
    </a>
    @include('storefront.partials.search-bar')
    <details class="sf-profile">
        <summary><span aria-hidden="true">👤</span> Moje konto</summary>
        <div>
            <?php $storefrontCustomer = auth()->user(); ?>
            <?php if ($storefrontCustomer): ?>
                <a href="{{ route('storefront.account') }}">Panel klienta</a>
                <a href="{{ route('storefront.account') }}#orders">Zamówienia</a>
                <form method="post" action="{{ route('storefront.logout') }}">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit">Wyloguj się</button>
                </form>
            <?php else: ?>
                <a href="{{ route('storefront.login') }}">Zaloguj się</a>
                <a href="{{ route('storefront.register') }}">Zarejestruj się</a>
            <?php endif; ?>
        </div>
    </details>
    <a class="sf-cart sf-cart--icon" href="{{ route('storefront.cart.index') }}" aria-label="Koszyk"><span aria-hidden="true">🛒</span><b>{{ $storefrontCartCount ?? 0 }}</b></a>
</div>
@php
    $storefrontMainLinks = [
        'Silniki' => '/kategoria-produktu/silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki',
        'Skrzynia biegów' => '/kategoria-produktu/uklad-napedowy/skrzynie-biegow-i-inne-elementy/automatyczna-skrzynia-biegow',
        'Filtry DPF' => '/kategoria-produktu/uklad-wydechowy-i-inne-elementy/elementy-systemu-kontroli-spalin/filtr-czastek-stalych-katalizator-fap-dpf',
        'Zwrotnice' => '/kategoria-produktu/os-przednia-i-inne-elementy/os-przednia/zwrotnica-kola-przedniego',
    ];
@endphp
<nav class="sf-nav"><div class="sf-container sf-nav__inner">
    @include('storefront.partials.category-menu')
    <div class="sf-nav__links" aria-label="Menu główne">
        <?php foreach ($storefrontMainLinks as $label => $url): ?>
            <a href="{{ $url }}">{{ $label }}</a>
        <?php endforeach; ?>
    </div>
    <span class="sf-phones">📞 <a href="tel:+48504266984">+48 504 266 984</a>&nbsp;&nbsp; <a href="tel:+48579152665">+48 579 152 665</a></span>
</div></nav>

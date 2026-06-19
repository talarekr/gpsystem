<style>
    @media (min-width: 1200px) {
        .sf-main-row {
            grid-template-columns: 190px minmax(0, 1fr) auto auto;
            gap: 16px;
        }
    }
    .sf-top .sf-top__inner {
        justify-content: space-between;
        flex-wrap: wrap;
        overflow-x: visible;
        white-space: normal;
        text-align: center;
    }
    .sf-top__links {
        display: inline-flex;
        gap: 22px;
        align-items: center;
    }
    .sf-language-select {
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #fff;
        color: var(--navy);
        padding: 5px 9px;
        font: inherit;
        font-weight: 700;
    }
    .sf-nav__links {
        display: flex;
        justify-content: center;
        gap: 24px;
        flex: 1 1 auto;
    }
    @media (min-width: 768px) {
        .sf-top .sf-top__inner {
            flex-wrap: nowrap;
            white-space: nowrap;
        }
    }
</style>
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
    @php($storefrontLogoPath = '/storage/brand/logo.png')
    @php($storefrontLogoPublicHtmlPath = dirname(base_path()) . '/public_html/storage/brand/logo.png')
    @php($storefrontLogoExists = file_exists($storefrontLogoPublicHtmlPath) || file_exists(public_path(ltrim($storefrontLogoPath, '/'))))
    <a class="sf-logo" href="{{ route('storefront.home') }}" aria-label="GP Swiss - strona główna">
        @if($storefrontLogoExists)
            <img src="{{ $storefrontLogoPath }}" alt="GP Swiss" style="display:block;height:clamp(38px,8vw,50px);width:auto;max-width:100%;object-fit:contain;">
        @else
            <span>GP</span>Swiss
        @endif
    </a>
    @include('storefront.partials.search-bar')
    <details class="sf-profile"><summary>Moje konto</summary><div><a href="#">Logowanie</a><a href="#">Zamówienia</a></div></details>
    <a class="sf-cart" href="{{ route('storefront.cart.index') }}">Koszyk <b>{{ $storefrontCartCount ?? 0 }}</b></a>
</div>
@php
    $storefrontMainLinks = [
        'Silniki' => '/kategoria-produktu/silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki',
        'Skrzynia biegów' => '/kategoria-produktu/uklad-klimatyzacji-wentylacji-chlodzenia-silnika-ogrzewanie-postojowe/chlodnice-i-wentylatory/chlodnica-oleju-skrzynia-biegow',
        'Filtry DPF' => '/kategoria-produktu/uklad-wydechowy-i-inne-elementy/elementy-systemu-kontroli-spalin/filtr-czastek-stalych-katalizator-fap-dpf',
        'Zwrotnice' => '/kategoria-produktu/os-przednia-i-inne-elementy/os-przednia/zwrotnica-kola-przedniego',
    ];
@endphp
<nav class="sf-nav"><div class="sf-container sf-nav__inner">
    @include('storefront.partials.category-menu')
    <div class="sf-nav__links" aria-label="Menu główne">
        @foreach($storefrontMainLinks as $label => $url)
            <a href="{{ $url }}">{{ $label }}</a>
        @endforeach
    </div>
    <span class="sf-phones">📞 +48 504 266 984&nbsp;&nbsp; +48 579 152 665</span>
</div></nav>

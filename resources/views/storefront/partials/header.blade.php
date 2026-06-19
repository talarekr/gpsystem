<div class="sf-promo">Części samochodowe z demontażu - szybka wysyłka i pomoc w doborze po VIN <button aria-label="Zamknij">×</button></div>
<header class="sf-top"><div class="sf-container sf-top__inner"><span>Zwrot do 14 dni</span><span>Oryginalne części używane</span><span>Pomoc: biuro@gpswiss.pl</span></div></header>
<div class="sf-container sf-main-row">
    @php($storefrontLogoPath = '/storage/brand/logo.png')
    @php($storefrontLogoPublicHtmlPath = dirname(base_path()) . '/public_html/storage/brand/logo.png')
    @php($storefrontLogoExists = file_exists($storefrontLogoPublicHtmlPath) || file_exists(public_path(ltrim($storefrontLogoPath, '/'))))
    <a class="sf-logo" href="{{ route('storefront.home') }}" aria-label="GP Swiss - strona główna">
        @if($storefrontLogoExists)
            <img src="{{ $storefrontLogoPath }}" alt="GP Swiss" style="display:block;height:clamp(44px,8vw,58px);width:auto;max-width:100%;object-fit:contain;">
        @else
            <span>GP</span>Swiss
        @endif
    </a>
    @include('storefront.partials.search-bar')
    <details class="sf-profile"><summary>Moje konto</summary><div><a href="#">Logowanie</a><a href="#">Zamówienia</a></div></details>
    <a class="sf-cart" href="{{ route('storefront.cart.index') }}">Koszyk <b>{{ $storefrontCartCount ?? 0 }}</b></a>
</div>
<nav class="sf-nav"><div class="sf-container sf-nav__inner">
    @include('storefront.partials.category-menu')
    @foreach($storefrontCategoryShortcuts ?? [] as $shortcut)
        <a href="{{ $shortcut['url'] }}">{{ $shortcut['label'] }}</a>
    @endforeach
    <span class="sf-phones">📞 +48 504 266 984&nbsp;&nbsp; +48 579 152 665</span>
</div></nav>

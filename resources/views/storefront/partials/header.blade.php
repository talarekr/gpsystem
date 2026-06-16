<header class="sf-header">
    <div class="sf-promo">Wybrane części do -10%! <button type="button" aria-label="Zamknij">×</button></div>
    <div class="sf-top"><div class="sf-container sf-top__inner"><a href="#footer-contact">Kontakt</a><span>Polski</span><span>Angielski</span><span>Francuski</span><span>Ukraiński</span><span>Niemiecki</span><strong>Rzetelna Firma</strong></div></div>
    <div class="sf-container sf-main-row">
        <a class="sf-logo" href="{{ route('storefront.home') }}"><span>GP</span>Swiss</a>
        @include('storefront.partials.search-bar')
        <details class="sf-profile"><summary>👤 Mój profil</summary><div><a href="#">Zaloguj się</a><a href="#">Zarejestruj się</a><a href="#">Ulubione</a><a href="#">Historia zamówień</a></div></details>
        <a class="sf-cart" href="#" aria-disabled="true">🛒 Koszyk <b>0</b></a>
    </div>
    <nav class="sf-nav"><div class="sf-container sf-nav__inner"><details class="sf-menu"><summary>☰ Menu</summary><div><a href="{{ route('storefront.catalog') }}">Wszystkie części</a><a href="{{ route('storefront.catalog', ['category' => 'silnik']) }}">Silniki</a><a href="{{ route('storefront.catalog', ['category' => 'skrzynia']) }}">Skrzynia biegów</a></div></details><a href="{{ route('storefront.catalog', ['category'=>'silnik']) }}">Silniki</a><a href="{{ route('storefront.catalog', ['category'=>'skrzynia']) }}">Skrzynia biegów</a><a href="{{ route('storefront.catalog', ['category'=>'dpf']) }}">Filtry DPF</a><a href="{{ route('storefront.catalog', ['category'=>'felgi']) }}">Felgi</a><a href="{{ route('storefront.catalog', ['category'=>'fotele']) }}">Fotele</a><a href="{{ route('storefront.catalog', ['category'=>'zwrotnice']) }}">Zwrotnice</a><span class="sf-phones">📞 +48 504 266 984&nbsp;&nbsp; +48 579 152 665</span></div></nav>
</header>

<div class="sf-storefront-header" aria-label="Nagłówek sklepu">
<header class="sf-top">
    <div class="sf-container sf-top__inner">
        <div class="sf-top__links">
            <a href="<?= e(route('storefront.contact')) ?>">Kontakt</a>
            <a href="<?= e(route('storefront.terms')) ?>">Regulamin</a>
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
    <?php
        $storefrontLogoPath = '/storage/brand/logo.png';
        $storefrontLogoPublicHtmlPath = dirname(base_path()) . '/public_html/storage/brand/logo.png';
        $storefrontLogoExists = file_exists($storefrontLogoPublicHtmlPath) || file_exists(public_path('storage/brand/logo.png'));
    ?>
    <a class="sf-logo" href="<?= e(route('storefront.home')) ?>" aria-label="GP Swiss - strona główna">
        <?php if ($storefrontLogoExists): ?>
            <img src="<?= e($storefrontLogoPath) ?>" alt="GP Swiss" style="display:block;height:clamp(38px,8vw,50px);width:auto;max-width:100%;object-fit:contain;">
        <?php else: ?>
            <span>GP</span>Swiss
        <?php endif; ?>
    </a>
    <?php echo $__env->make('storefront.partials.search-bar', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
    <details class="sf-profile">
        <summary aria-label="Moje konto"><span aria-hidden="true">👤</span> <span class="sf-profile__label">Moje konto</span></summary>
        <div>
            <?php $storefrontCustomer = auth()->user(); ?>
            <?php if ($storefrontCustomer): ?>
                <a href="<?= e(route('storefront.account')) ?>">Panel klienta</a>
                <a href="<?= e(route('storefront.account')) ?>#orders">Zamówienia</a>
                <form method="post" action="<?= e(route('storefront.logout')) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit">Wyloguj się</button>
                </form>
            <?php else: ?>
                <a href="<?= e(route('storefront.login')) ?>">Zaloguj się</a>
                <a href="<?= e(route('storefront.register')) ?>">Zarejestruj się</a>
            <?php endif; ?>
        </div>
    </details>
    <a class="sf-cart sf-cart--icon" href="<?= e(route('storefront.cart.index')) ?>" aria-label="Koszyk"><span aria-hidden="true">🛒</span><b><?= e($storefrontCartCount ?? 0) ?></b></a>
</div>
<?php
    $storefrontMainLinks = [
        'Silniki' => '/kategoria-produktu/silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki',
        'Skrzynia biegów' => '/kategoria-produktu/uklad-napedowy/skrzynie-biegow-i-inne-elementy/automatyczna-skrzynia-biegow',
        'Filtry DPF' => '/kategoria-produktu/uklad-wydechowy-i-inne-elementy/elementy-systemu-kontroli-spalin/filtr-czastek-stalych-katalizator-fap-dpf',
        'Zwrotnice' => '/kategoria-produktu/os-przednia-i-inne-elementy/os-przednia/zwrotnica-kola-przedniego',
    ];
?>
<nav class="sf-nav"><div class="sf-container sf-nav__inner">
    <?php echo $__env->make('storefront.partials.category-menu', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
    <div class="sf-nav__links" aria-label="Menu główne">
        <?php foreach ($storefrontMainLinks as $label => $url): ?>
            <a href="<?= e($url) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <a class="sf-phones sf-phones--direct" href="tel:+48504266984" aria-label="Zadzwoń pod numer +48 504 266 984">
        <span aria-hidden="true">📞</span> <span class="sf-phones__number">+48 504 266 984</span>
    </a>
</div></nav>
</div>

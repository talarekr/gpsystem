<footer class="sf-footer" id="footer-contact">
    <div class="sf-container sf-footer__grid">
        <section class="sf-footer__column" aria-labelledby="footer-company-heading">
            <h3 id="footer-company-heading">{{ __('storefront.company_details') }}</h3>
            <p>
                <strong>{{ __('storefront.company_name') }}:</strong> Gregor Swiss Grzegorz Paciorek<br>
                ul. Milanowska 137<br>
                08-460 Sobolew<br>
                <strong>{{ __('storefront.tax_id') }}:</strong> 8262157853<br>
                <strong>REGON:</strong> 368948917
            </p>
        </section>

        <section class="sf-footer__column" aria-labelledby="footer-info-heading">
            <h3 id="footer-info-heading">{{ __('storefront.useful_info') }}</h3>
            <nav class="sf-footer__links" aria-label="{{ __('storefront.useful_info') }}">
                <a href="{{ route('storefront.terms') }}">{{ __('storefront.terms') }}</a>
                <a href="{{ route('storefront.privacy-policy') }}">{{ __('storefront.privacy_policy') }}</a>
            </nav>
        </section>

        <section class="sf-footer__column" aria-labelledby="footer-contact-heading">
            <h3 id="footer-contact-heading">{{ __('storefront.contact') }}</h3>
            <p>
                tel. <a href="tel:+48504266984">504 266 984</a><br>
                e-mail: <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a>
            </p>
        </section>
    </div>
</footer>

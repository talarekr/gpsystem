<footer class="sf-footer" id="footer-contact">
    <div class="sf-container sf-footer__grid">
        <section class="sf-footer__column" aria-labelledby="footer-company-heading">
            <h3 id="footer-company-heading">Dane firmy</h3>
            <p>
                <strong>Nazwa firmy:</strong> Gregor Swiss Grzegorz Paciorek<br>
                ul. Milanowska 137<br>
                08-460 Sobolew<br>
                <strong>NIP:</strong> 8262157853<br>
                <strong>REGON:</strong> 368948917
            </p>
        </section>

        <section class="sf-footer__column" aria-labelledby="footer-info-heading">
            <h3 id="footer-info-heading">Przydatne informacje</h3>
            <nav class="sf-footer__links" aria-label="Przydatne informacje">
                <a href="{{ route('storefront.terms') }}">Regulamin</a>
                <a href="{{ route('storefront.privacy-policy') }}">Polityka prywatności</a>
            </nav>
        </section>

        <section class="sf-footer__column" aria-labelledby="footer-contact-heading">
            <h3 id="footer-contact-heading">Kontakt</h3>
            <p>
                tel. <a href="tel:+48504266984">504 266 984</a><br>
                e-mail: <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a>
            </p>
        </section>
    </div>
</footer>

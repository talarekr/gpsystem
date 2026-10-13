<p>
    @include('storefront.fr.company-address')<br>
    {{ config('storefront-contact.fr.legal_form') }}<br>
    SIREN : {{ config('storefront-contact.fr.siren') }}<br>
    SIRET : {{ config('storefront-contact.fr.siret') }}<br>
    RCS Paris : {{ config('storefront-contact.fr.rcs') }}<br>
    TVA intracommunautaire : {{ config('storefront-contact.fr.vat') }}<br>
    Code APE : {{ config('storefront-contact.fr.ape') }}<br>
    Gérant : {{ config('storefront-contact.fr.manager') }}<br>
    E-mail : <a href="mailto:{{ config('storefront-contact.fr.email') }}">{{ config('storefront-contact.fr.email') }}</a><br>
    Téléphone : <a href="tel:{{ config('storefront-contact.fr.phone_uri') }}">{{ config('storefront-contact.fr.phone') }}</a>
</p>

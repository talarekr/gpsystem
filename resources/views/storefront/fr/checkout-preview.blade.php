<div class="sf-container sf-page sf-cart-page">
    <h1>Préparer ma commande</h1>
    <p><strong>Paiements temporairement indisponibles.</strong> Vous pouvez préparer vos coordonnées. Enregistrer cet aperçu ne crée aucune commande, ne réserve aucun produit et ne déclenche aucun paiement.</p>
    <form method="post" action="{{ route('storefront.fr.checkout.preview') }}" class="sf-checkout-card">
        @csrf
        <label class="sf-field">Type de client
            <select name="customer_type"><option value="private" @selected(old('customer_type', $draft['address']['customer_type']) === 'private')>Particulier</option><option value="company" @selected(old('customer_type', $draft['address']['customer_type']) === 'company')>Entreprise</option></select>
        </label>
        @foreach(['billing_company_name' => 'Raison sociale (obligatoire pour une entreprise)', 'billing_siret' => 'SIRET (facultatif)', 'billing_vat_number' => 'Numéro de TVA (facultatif)', 'billing_email' => 'Adresse e-mail', 'billing_phone' => 'Téléphone'] as $field => $label)
            <label class="sf-field">{{ $label }}<input type="{{ $field === 'billing_email' ? 'email' : 'text' }}" name="{{ $field }}" value="{{ old($field, $draft['address'][$field]) }}" @required(in_array($field, ['billing_email', 'billing_phone'])) maxlength="255"></label>
            @error($field)<p>{{ $message }}</p>@enderror
        @endforeach
        <label class="sf-field">Adresse de livraison
            <select name="shipping_same_as_billing"><option value="1" @selected((string) old('shipping_same_as_billing', (int) $draft['address']['shipping_same_as_billing']) === '1')>Identique à la facturation</option><option value="0" @selected((string) old('shipping_same_as_billing', (int) $draft['address']['shipping_same_as_billing']) === '0')>Différente — remplir l’adresse de livraison ci-dessous</option></select>
        </label>
        @foreach(['billing' => 'Adresse de facturation', 'shipping' => 'Adresse de livraison (si différente)'] as $prefix => $heading)
            <h2>{{ $heading }}</h2>
            @foreach(['first_name' => 'Prénom', 'last_name' => 'Nom', 'address_line1' => 'Numéro et voie', 'address_line2' => 'Complément d’adresse (facultatif)', 'postal_code' => 'Code postal', 'city' => 'Ville'] as $suffix => $label)
                @php($field = $prefix.'_'.$suffix)
                <label class="sf-field">{{ $label }}<input name="{{ $field }}" value="{{ old($field, $draft['address'][$field]) }}" @required($prefix === 'billing' && $suffix !== 'address_line2') maxlength="255"></label>
                @error($field)<p>{{ $message }}</p>@enderror
            @endforeach
            <label class="sf-field">Pays<select name="{{ $prefix }}_country"><option value="FR">France</option></select></label>
            @error($prefix.'_country')<p>{{ $message }}</p>@enderror
        @endforeach
        <p>Livraison : transporteur en France. {{ $draft['shipping_ready'] ? 'Tarif indiqué dans le récapitulatif.' : 'Le tarif de livraison n’est pas encore disponible.' }}</p>
        <button class="sf-btn" type="submit">Enregistrer mon aperçu</button>
    </form>
    @include('storefront.fr.order-summary')
    <button class="sf-btn" type="button" disabled>Commander et payer — indisponible</button>
    <a class="sf-btn sf-btn--outline" href="{{ route('storefront.fr.cart.index') }}">{{ __('storefront.back_to_cart') }}</a>
</div>

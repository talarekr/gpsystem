<div class="sf-container sf-page sf-cart-page sf-checkout-page sf-fr-checkout">
<div class="sf-cart-head"><h1>Commande</h1><a class="sf-btn sf-btn--outline" href="{{ route('storefront.fr.cart.index') }}">{{ __('storefront.back_to_cart') }}</a></div>
@if($draft['stripe_ready'] && $draft['stripe_mode'] === 'test')<p class="sf-flash sf-flash--warning">Mode test Stripe — aucune expédition ni modification du stock.</p>@endif
@if(!$draft['stripe_ready'])<p class="sf-flash sf-flash--warning">Paiements temporairement indisponibles.</p>@endif
<div class="sf-checkout-layout">
<div class="sf-checkout-form">
<form id="fr-address" method="post" action="{{ route('storefront.fr.checkout.preview') }}" class="sf-checkout-card sf-checkout-flow">
@csrf
<section class="sf-checkout-section">
<h2>Informations de facturation</h2>
<div class="sf-checkout-options sf-checkout-options--segmented">
<label><input type="radio" name="customer_type" value="private" @checked(old('customer_type', $draft['address']['customer_type']) === 'private')><span>Particulier</span></label>
<label><input type="radio" name="customer_type" value="company" @checked(old('customer_type', $draft['address']['customer_type']) === 'company')><span>Entreprise</span></label>
</div>
<div class="sf-checkout-grid sf-checkout-grid--spaced">
<label class="sf-field sf-field--half sf-fr-company-field"><span>Raison sociale</span><input type="text" name="billing_company_name" value="{{ old('billing_company_name', $draft['address']['billing_company_name']) }}"  autocomplete="organization">@error('billing_company_name')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half sf-fr-company-field"><span>SIRET (facultatif)</span><input type="text" name="billing_siret" value="{{ old('billing_siret', $draft['address']['billing_siret']) }}"  autocomplete="">@error('billing_siret')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half sf-fr-company-field"><span>TVA (facultatif)</span><input type="text" name="billing_vat_number" value="{{ old('billing_vat_number', $draft['address']['billing_vat_number']) }}"  autocomplete="">@error('billing_vat_number')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Prénom</span><input type="text" name="billing_first_name" value="{{ old('billing_first_name', $draft['address']['billing_first_name']) }}" required autocomplete="given-name">@error('billing_first_name')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Nom</span><input type="text" name="billing_last_name" value="{{ old('billing_last_name', $draft['address']['billing_last_name']) }}" required autocomplete="family-name">@error('billing_last_name')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Numéro et voie</span><input type="text" name="billing_address_line1" value="{{ old('billing_address_line1', $draft['address']['billing_address_line1']) }}" required autocomplete="address-line1">@error('billing_address_line1')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Complément d’adresse (facultatif)</span><input type="text" name="billing_address_line2" value="{{ old('billing_address_line2', $draft['address']['billing_address_line2']) }}"  autocomplete="address-line2">@error('billing_address_line2')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Code postal</span><input type="text" name="billing_postal_code" value="{{ old('billing_postal_code', $draft['address']['billing_postal_code']) }}" required autocomplete="postal-code">@error('billing_postal_code')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Ville</span><input type="text" name="billing_city" value="{{ old('billing_city', $draft['address']['billing_city']) }}" required autocomplete="address-level2">@error('billing_city')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Adresse e-mail</span><input type="email" name="billing_email" value="{{ old('billing_email', $draft['address']['billing_email']) }}" required autocomplete="email">@error('billing_email')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Téléphone</span><input type="tel" name="billing_phone" value="{{ old('billing_phone', $draft['address']['billing_phone']) }}" required autocomplete="tel">@error('billing_phone')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Pays</span><input value="France" readonly><input type="hidden" name="billing_country" value="FR"></label>
</div></section>
<section class="sf-checkout-section"><h2>Adresse de livraison</h2>
<div class="sf-checkout-options">
<label><input type="radio" name="shipping_same_as_billing" value="1" @checked((string) old('shipping_same_as_billing', (int) $draft['address']['shipping_same_as_billing']) === '1')><span>Identique à la facturation</span></label>
<label><input type="radio" name="shipping_same_as_billing" value="0" @checked((string) old('shipping_same_as_billing', (int) $draft['address']['shipping_same_as_billing']) === '0')><span>Utiliser une autre adresse</span></label>
</div><div class="sf-checkout-grid sf-shipping-fields">
<label class="sf-field sf-field--half"><span>Prénom</span><input type="text" name="shipping_first_name" value="{{ old('shipping_first_name', $draft['address']['shipping_first_name']) }}"  autocomplete="shipping given-name">@error('shipping_first_name')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Nom</span><input type="text" name="shipping_last_name" value="{{ old('shipping_last_name', $draft['address']['shipping_last_name']) }}"  autocomplete="shipping family-name">@error('shipping_last_name')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Numéro et voie</span><input type="text" name="shipping_address_line1" value="{{ old('shipping_address_line1', $draft['address']['shipping_address_line1']) }}"  autocomplete="shipping address-line1">@error('shipping_address_line1')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Complément d’adresse (facultatif)</span><input type="text" name="shipping_address_line2" value="{{ old('shipping_address_line2', $draft['address']['shipping_address_line2']) }}"  autocomplete="shipping address-line2">@error('shipping_address_line2')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Code postal</span><input type="text" name="shipping_postal_code" value="{{ old('shipping_postal_code', $draft['address']['shipping_postal_code']) }}"  autocomplete="shipping postal-code">@error('shipping_postal_code')<small>{{ $message }}</small>@enderror</label>
<label class="sf-field sf-field--half"><span>Ville</span><input type="text" name="shipping_city" value="{{ old('shipping_city', $draft['address']['shipping_city']) }}"  autocomplete="shipping address-level2">@error('shipping_city')<small>{{ $message }}</small>@enderror</label>
</div><input type="hidden" name="shipping_country" value="FR"></section>
<section class="sf-checkout-section"><h2>Méthode de livraison</h2><p>Transporteur en France</p><p>Frais de livraison : <strong>{{ $draft['shipping_cents'] === null ? 'Livraison indisponible pour cet article' : number_format($draft['shipping_cents'] / 100, 2, ',', ' ').' EUR' }}</strong></p></section>
<button class="sf-btn" type="submit">Enregistrer mes coordonnées</button>
</form>
<section class="sf-checkout-card"><h2>Paiement</h2><p>Carte bancaire — Stripe</p><p>Vous serez redirigé vers le paiement sécurisé.</p></section>
<section class="sf-checkout-card"><h2>Acceptations</h2>
<label class="sf-checkout-terms"><input type="checkbox" name="terms" value="1" required form="fr-stripe-payment"><span>J’accepte les <a href="{{ route('storefront.terms') }}">conditions générales de vente</a> et j’ai pris connaissance de la <a href="{{ route('storefront.privacy-policy') }}">politique de confidentialité</a>.</span></label>
@error('terms')<small class="sf-checkout-error">{{ $message }}</small>@enderror
@error('payment')<p class="sf-checkout-error" role="alert">{{ $message }}</p>@enderror
</section>
</div>
<aside class="sf-cart-summary sf-checkout-summary">
@include('storefront.fr.order-summary')
@if($draft['can_place_order'])
<form id="fr-stripe-payment" method="post" action="{{ route('storefront.fr.checkout.store') }}">
@csrf<input type="hidden" name="checkout_key" value="{{ $draft['checkout_key'] }}"><button class="sf-btn" type="submit">Payer par carte</button>
</form>
@else
<p>Enregistrez vos coordonnées complètes. Une seule pièce, en quantité un, avec un prix et une livraison disponibles est nécessaire.</p><button class="sf-btn sf-btn--disabled" type="button" disabled>Payer par carte</button>
@endif
</aside></div></div>
<script>
(() => {
    const form = document.getElementById('fr-address');
    const payment = document.getElementById('fr-stripe-payment');
    if (!form) return;
    const update = () => {
        const company = form.querySelector('[name="customer_type"]:checked')?.value === 'company';
        form.querySelectorAll('.sf-fr-company-field').forEach(label => {
            label.hidden = !company;
            label.querySelector('input').disabled = !company;
            label.querySelector('input').required = company && label.querySelector('input').name === 'billing_company_name';
        });
        const different = form.querySelector('[name="shipping_same_as_billing"]:checked')?.value === '0';
        const shipping = form.querySelector('.sf-shipping-fields');
        shipping.hidden = !different;
        shipping.querySelectorAll('input').forEach(input => {
            input.disabled = !different;
            input.required = different && !input.name.endsWith('address_line2');
        });
    };
    const markDirty = () => {
        if (payment) {
            payment.querySelector('button').disabled = true;
            payment.querySelector('button').classList.add('sf-btn--disabled');
        }
    };
    form.addEventListener('input', markDirty);
    form.addEventListener('change', () => { update(); markDirty(); });
    update();
})();
</script>

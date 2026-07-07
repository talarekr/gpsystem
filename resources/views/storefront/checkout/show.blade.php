@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page sf-checkout-page">
    @include('storefront.partials.breadcrumbs')
    <div class="sf-cart-head">
        <h1>Zamówienie</h1>
        <a class="sf-btn sf-btn--outline" href="{{ route('storefront.cart.index') }}">Wróć do koszyka</a>
    </div>

    <form method="post" action="{{ route('storefront.checkout.store') }}" class="sf-checkout-layout">
        @csrf
        <div class="sf-checkout-form">
            @if($errors->any())
                <div class="sf-alert sf-alert--error">Sprawdź poprawność formularza.</div>
            @endif

            <section class="sf-checkout-card sf-checkout-flow">
                <div class="sf-checkout-section" data-checkout-toggle>
                    <h2>Informacje rozliczeniowe</h2>
                    <p class="sf-checkout-section__label">Zamów jako:</p>
                    <div class="sf-checkout-options sf-checkout-options--segmented">
                        <label><input type="radio" name="customer_type" value="private" @checked(old('customer_type', 'private') === 'private')><span>Osoba prywatna</span></label>
                        <label><input type="radio" name="customer_type" value="company" @checked(old('customer_type') === 'company')><span>Firma</span></label>
                    </div>
                    @error('customer_type')<small class="sf-checkout-error">{{ $message }}</small>@enderror

                    <div class="sf-checkout-grid sf-checkout-grid--spaced">
                        <label class="sf-field sf-field--half sf-company-field">
                            <span>NIP</span>
                            <input name="billing_nip" value="{{ old('billing_nip') }}" autocomplete="off">
                            @error('billing_nip')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half sf-company-field">
                            <span>Nazwa firmy</span>
                            <input name="billing_company_name" value="{{ old('billing_company_name') }}" autocomplete="organization">
                            @error('billing_company_name')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>Imię</span>
                            <input name="billing_first_name" value="{{ old('billing_first_name') }}" required autocomplete="given-name">
                            @error('billing_first_name')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>Nazwisko</span>
                            <input name="billing_last_name" value="{{ old('billing_last_name') }}" required autocomplete="family-name">
                            @error('billing_last_name')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>Nazwa ulicy</span>
                            <input name="billing_street" value="{{ old('billing_street') }}" required autocomplete="address-line1">
                            @error('billing_street')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--third">
                            <span>Numer budynku</span>
                            <input name="billing_building_number" value="{{ old('billing_building_number') }}" required autocomplete="address-line2">
                            @error('billing_building_number')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--third">
                            <span>Kod pocztowy</span>
                            <input name="billing_postal_code" value="{{ old('billing_postal_code') }}" required autocomplete="postal-code">
                            @error('billing_postal_code')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>Miasto</span>
                            <input name="billing_city" value="{{ old('billing_city') }}" required autocomplete="address-level2">
                            @error('billing_city')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>Numer telefonu</span>
                            <input name="billing_phone" value="{{ old('billing_phone') }}" required autocomplete="tel">
                            @error('billing_phone')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>Adres e-mail</span>
                            <input type="email" name="billing_email" value="{{ old('billing_email') }}" required autocomplete="email">
                            @error('billing_email')<small>{{ $message }}</small>@enderror
                        </label>
                    </div>
                </div>

                <div class="sf-checkout-section" data-shipping-toggle>
                    <h2>Adres wysyłki</h2>
                    <div class="sf-checkout-options">
                        <label><input type="radio" name="shipping_same_as_billing" value="1" @checked(old('shipping_same_as_billing', '1') === '1')><span>Takie same jak w informacjach rozliczeniowych</span></label>
                        <label><input type="radio" name="shipping_same_as_billing" value="0" @checked(old('shipping_same_as_billing') === '0')><span>Inny adres wysyłki</span></label>
                    </div>
                    @error('shipping_same_as_billing')<small class="sf-checkout-error">{{ $message }}</small>@enderror

                    <div class="sf-checkout-grid sf-shipping-fields">
                        <label class="sf-field sf-field--half"><span>Imię</span><input name="shipping_first_name" value="{{ old('shipping_first_name') }}" autocomplete="shipping given-name">@error('shipping_first_name')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--half"><span>Nazwisko</span><input name="shipping_last_name" value="{{ old('shipping_last_name') }}" autocomplete="shipping family-name">@error('shipping_last_name')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--half"><span>Nazwa ulicy</span><input name="shipping_street" value="{{ old('shipping_street') }}" autocomplete="shipping address-line1">@error('shipping_street')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--third"><span>Numer budynku</span><input name="shipping_building_number" value="{{ old('shipping_building_number') }}" autocomplete="shipping address-line2">@error('shipping_building_number')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--third"><span>Kod pocztowy</span><input name="shipping_postal_code" value="{{ old('shipping_postal_code') }}" autocomplete="shipping postal-code">@error('shipping_postal_code')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--half"><span>Miasto</span><input name="shipping_city" value="{{ old('shipping_city') }}" autocomplete="shipping address-level2">@error('shipping_city')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--half"><span>Kraj</span><input name="shipping_country" value="{{ old('shipping_country', 'PL') }}" maxlength="2" autocomplete="shipping country">@error('shipping_country')<small>{{ $message }}</small>@enderror</label>
                    </div>
                </div>

                <div class="sf-checkout-section">
                    <h2>Metoda wysyłki</h2>
                    <div class="sf-checkout-options">
                        <label><input type="radio" name="shipping_method" value="courier" @checked(old('shipping_method', 'courier') === 'courier') required><span>Kurier - 0,00 zł</span></label>
                        <label><input type="radio" name="shipping_method" value="courier_cod" @checked(old('shipping_method') === 'courier_cod') required><span>Kurier pobranie - 0,00 zł</span></label>
                        <label><input type="radio" name="shipping_method" value="pickup" @checked(old('shipping_method') === 'pickup') required><span>Odbiór osobisty - 0,00 zł</span></label>
                    </div>
                    @error('shipping_method')<small class="sf-checkout-error">{{ $message }}</small>@enderror
                </div>

                <div class="sf-checkout-section">
                    <h2>Metoda płatności</h2>
                    <div class="sf-checkout-options">
                        <label><input type="radio" name="payment_method" value="payu" @checked(old('payment_method', 'payu') === 'payu') required><span>PayU Checkout</span></label>
                        <label><input type="radio" name="payment_method" value="blik" @checked(old('payment_method') === 'blik') required><span>BLIK przez PayU Checkout</span></label>
                    </div>
                    @error('payment_method')<small class="sf-checkout-error">{{ $message }}</small>@enderror
                </div>
            </section>

            <section class="sf-checkout-card">
                <h2>Akceptacje</h2>
                <label class="sf-checkout-terms">
                    <input type="checkbox" name="terms" value="1" @checked(old('terms')) required>
                    <span>Akceptuję <a href="{{ route('storefront.terms') }}" target="_blank">regulamin</a>.</span>
                </label>
                @error('terms')<small class="sf-checkout-error">{{ $message }}</small>@enderror
            </section>
        </div>

        <aside class="sf-cart-summary sf-checkout-summary">
            <h2>Podsumowanie</h2>
            <div class="sf-checkout-summary__items">
                @foreach($items as $item)
                    <div class="sf-checkout-summary__row sf-checkout-summary__row--product">
                        <span class="sf-checkout-summary__product-title">{{ $item['name'] }} <em>× {{ $item['quantity'] }}</em></span>
                        <strong>{{ number_format((float) $item['line_total'], 2, ',', ' ') }} {{ $item['currency'] }}</strong>
                    </div>
                @endforeach
            </div>
            <div class="sf-checkout-summary__row"><span>Dostawa</span><strong>0,00 {{ $items->first()['currency'] ?? 'PLN' }}</strong></div>
            <div class="sf-checkout-summary__row sf-checkout-summary__row--total"><span>Razem</span><strong>{{ number_format((float) $subtotal, 2, ',', ' ') }} {{ $items->first()['currency'] ?? 'PLN' }}</strong></div>
            <button class="sf-btn" type="submit">Złóż zamówienie i zapłać</button>
        </aside>
    </form>
</div>
<script>
    (() => {
        const form = document.querySelector('.sf-checkout-layout');
        if (! form) return;
        const update = () => {
            const isCompany = form.querySelector('input[name="customer_type"]:checked')?.value === 'company';
            form.querySelectorAll('.sf-company-field').forEach((field) => field.hidden = ! isCompany);
            const customShipping = form.querySelector('input[name="shipping_same_as_billing"]:checked')?.value === '0';
            const shippingFields = form.querySelector('.sf-shipping-fields');
            if (shippingFields) shippingFields.hidden = ! customShipping;
        };
        form.addEventListener('change', (event) => {
            if (event.target.matches('input[name="customer_type"], input[name="shipping_same_as_billing"]')) update();
        });
        update();
    })();
</script>
@endsection

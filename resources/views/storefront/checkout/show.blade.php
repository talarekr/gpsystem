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

            <section class="sf-checkout-card">
                <h2>Dane klienta</h2>
                <div class="sf-checkout-grid">
                    <label class="sf-field sf-field--half">
                        <span>Imię i nazwisko / nazwa firmy</span>
                        <input name="customer_name" value="{{ old('customer_name') }}" required autocomplete="name">
                        @error('customer_name')<small>{{ $message }}</small>@enderror
                    </label>
                    <label class="sf-field sf-field--half">
                        <span>Nazwa firmy</span>
                        <input name="company_name" value="{{ old('company_name') }}" autocomplete="organization">
                        @error('company_name')<small>{{ $message }}</small>@enderror
                    </label>
                    <label class="sf-field sf-field--half">
                        <span>E-mail</span>
                        <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
                        @error('email')<small>{{ $message }}</small>@enderror
                    </label>
                    <label class="sf-field sf-field--half">
                        <span>Telefon</span>
                        <input name="phone" value="{{ old('phone') }}" required autocomplete="tel">
                        @error('phone')<small>{{ $message }}</small>@enderror
                    </label>
                </div>
            </section>

            <section class="sf-checkout-card">
                <h2>Adres dostawy</h2>
                <div class="sf-checkout-grid">
                    <label class="sf-field sf-field--full">
                        <span>Ulica i numer</span>
                        <input name="address_line1" value="{{ old('address_line1') }}" required autocomplete="street-address">
                        @error('address_line1')<small>{{ $message }}</small>@enderror
                    </label>
                    <label class="sf-field sf-field--third">
                        <span>Kod pocztowy</span>
                        <input name="postal_code" value="{{ old('postal_code') }}" required autocomplete="postal-code">
                        @error('postal_code')<small>{{ $message }}</small>@enderror
                    </label>
                    <label class="sf-field sf-field--third">
                        <span>Miasto</span>
                        <input name="city" value="{{ old('city') }}" required autocomplete="address-level2">
                        @error('city')<small>{{ $message }}</small>@enderror
                    </label>
                    <label class="sf-field sf-field--third">
                        <span>Kraj</span>
                        <input name="country" value="{{ old('country', 'PL') }}" maxlength="2" required autocomplete="country">
                        @error('country')<small>{{ $message }}</small>@enderror
                    </label>
                </div>
            </section>

            <section class="sf-checkout-card">
                <h2>Dane dodatkowe</h2>
                <div class="sf-checkout-grid">
                    <label class="sf-field sf-field--half">
                        <span>NIP</span>
                        <input name="nip" value="{{ old('nip') }}">
                        @error('nip')<small>{{ $message }}</small>@enderror
                    </label>
                    <label class="sf-field sf-field--full">
                        <span>Uwagi</span>
                        <textarea name="notes" rows="4">{{ old('notes') }}</textarea>
                        @error('notes')<small>{{ $message }}</small>@enderror
                    </label>
                </div>
            </section>


            <section class="sf-checkout-card">
                <h2>Płatność</h2>
                <label class="sf-checkout-terms">
                    <input type="radio" name="payment_method" value="payu" @checked(old('payment_method', 'payu') === 'payu') required>
                    <span>PayU Checkout (karty, szybkie przelewy, BLIK)</span>
                </label>
                <label class="sf-checkout-terms">
                    <input type="radio" name="payment_method" value="blik" @checked(old('payment_method') === 'blik') required>
                    <span>BLIK przez PayU Checkout</span>
                </label>
                @error('payment_method')<small class="sf-checkout-error">{{ $message }}</small>@enderror
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
@endsection

@extends('layouts.storefront')

@section('content')
@php
    $checkoutCustomerType = old('customer_type', 'private');
    $checkoutShippingSameAsBilling = old('shipping_same_as_billing', '1') !== '0';
@endphp
<div class="sf-container sf-page sf-cart-page sf-checkout-page">
    @include('storefront.partials.breadcrumbs')
    <div class="sf-cart-head">
        <h1>{{ __('storefront.checkout') }}</h1>
        <a class="sf-btn sf-btn--outline" href="{{ route('storefront.cart.index') }}">{{ __('storefront.back_to_cart') }}</a>
    </div>

    <form method="post" action="{{ route('storefront.checkout.store') }}" class="sf-checkout-layout">
        @csrf
        <div class="sf-checkout-form">
            @if($errors->any())
                <div class="sf-alert sf-alert--error">{{ __('storefront.check_form') }}</div>
            @endif

            <section class="sf-checkout-card sf-checkout-flow">
                <div class="sf-checkout-section" data-checkout-toggle>
                    <h2>{{ __('storefront.billing_info') }}</h2>
                    <p class="sf-checkout-section__label">{{ __('storefront.order_as') }}</p>
                    <div class="sf-checkout-options sf-checkout-options--segmented">
                        <label><input type="radio" name="customer_type" value="private" @checked(old('customer_type', 'private') === 'private')><span>{{ __('storefront.private_person') }}</span></label>
                        <label><input type="radio" name="customer_type" value="company" @checked(old('customer_type') === 'company')><span>{{ __('storefront.company') }}</span></label>
                    </div>
                    @error('customer_type')<small class="sf-checkout-error">{{ $message }}</small>@enderror

                    <div class="sf-checkout-grid sf-checkout-grid--spaced">
                        <label class="sf-field sf-field--half sf-company-field" @if($checkoutCustomerType !== 'company') hidden aria-hidden="true" @endif>
                            <span>{{ __('storefront.tax_id') }}</span>
                            <input name="billing_nip" @if($checkoutCustomerType !== 'company') disabled @endif value="{{ old('billing_nip') }}" autocomplete="off">
                            @error('billing_nip')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half sf-company-field" @if($checkoutCustomerType !== 'company') hidden aria-hidden="true" @endif>
                            <span>{{ __('storefront.company_name') }}</span>
                            <input name="billing_company_name" @if($checkoutCustomerType !== 'company') disabled @endif value="{{ old('billing_company_name') }}" autocomplete="organization">
                            @error('billing_company_name')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>{{ __('storefront.first_name') }}</span>
                            <input name="billing_first_name" value="{{ old('billing_first_name') }}" required autocomplete="given-name">
                            @error('billing_first_name')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>{{ __('storefront.last_name') }}</span>
                            <input name="billing_last_name" value="{{ old('billing_last_name') }}" required autocomplete="family-name">
                            @error('billing_last_name')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>{{ __('storefront.street') }}</span>
                            <input name="billing_street" value="{{ old('billing_street') }}" required autocomplete="address-line1">
                            @error('billing_street')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--third">
                            <span>{{ __('storefront.building_number') }}</span>
                            <input name="billing_building_number" value="{{ old('billing_building_number') }}" required autocomplete="address-line2">
                            @error('billing_building_number')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--third">
                            <span>{{ __('storefront.postal_code') }}</span>
                            <input name="billing_postal_code" value="{{ old('billing_postal_code') }}" required autocomplete="postal-code">
                            @error('billing_postal_code')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>{{ __('storefront.city') }}</span>
                            <input name="billing_city" value="{{ old('billing_city') }}" required autocomplete="address-level2">
                            @error('billing_city')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>{{ __('storefront.phone') }}</span>
                            <input name="billing_phone" value="{{ old('billing_phone') }}" required autocomplete="tel">
                            @error('billing_phone')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="sf-field sf-field--half">
                            <span>{{ __('storefront.email') }}</span>
                            <input type="email" name="billing_email" value="{{ old('billing_email') }}" required autocomplete="email">
                            @error('billing_email')<small>{{ $message }}</small>@enderror
                        </label>
                    </div>
                </div>

                <div class="sf-checkout-section" data-shipping-toggle>
                    <h2>{{ __('storefront.shipping_address') }}</h2>
                    <div class="sf-checkout-options">
                        <label><input type="radio" name="shipping_same_as_billing" value="1" @checked(old('shipping_same_as_billing', '1') === '1')><span>{{ __('storefront.same_as_billing') }}</span></label>
                        <label><input type="radio" name="shipping_same_as_billing" value="0" @checked(old('shipping_same_as_billing') === '0')><span>{{ __('storefront.different_shipping') }}</span></label>
                    </div>
                    @error('shipping_same_as_billing')<small class="sf-checkout-error">{{ $message }}</small>@enderror

                    <div class="sf-checkout-grid sf-shipping-fields" @if($checkoutShippingSameAsBilling) hidden aria-hidden="true" @endif>
                        <label class="sf-field sf-field--half"><span>{{ __('storefront.first_name') }}</span><input name="shipping_first_name" @if($checkoutShippingSameAsBilling) disabled @endif value="{{ old('shipping_first_name') }}" autocomplete="shipping given-name">@error('shipping_first_name')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--half"><span>{{ __('storefront.last_name') }}</span><input name="shipping_last_name" @if($checkoutShippingSameAsBilling) disabled @endif value="{{ old('shipping_last_name') }}" autocomplete="shipping family-name">@error('shipping_last_name')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--half"><span>{{ __('storefront.street') }}</span><input name="shipping_street" @if($checkoutShippingSameAsBilling) disabled @endif value="{{ old('shipping_street') }}" autocomplete="shipping address-line1">@error('shipping_street')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--third"><span>{{ __('storefront.building_number') }}</span><input name="shipping_building_number" @if($checkoutShippingSameAsBilling) disabled @endif value="{{ old('shipping_building_number') }}" autocomplete="shipping address-line2">@error('shipping_building_number')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--third"><span>{{ __('storefront.postal_code') }}</span><input name="shipping_postal_code" @if($checkoutShippingSameAsBilling) disabled @endif value="{{ old('shipping_postal_code') }}" autocomplete="shipping postal-code">@error('shipping_postal_code')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--half"><span>{{ __('storefront.city') }}</span><input name="shipping_city" @if($checkoutShippingSameAsBilling) disabled @endif value="{{ old('shipping_city') }}" autocomplete="shipping address-level2">@error('shipping_city')<small>{{ $message }}</small>@enderror</label>
                        <label class="sf-field sf-field--half"><span>{{ __('storefront.country') }}</span><input name="shipping_country" @if($checkoutShippingSameAsBilling) disabled @endif value="{{ old('shipping_country', 'PL') }}" maxlength="2" autocomplete="shipping country">@error('shipping_country')<small>{{ $message }}</small>@enderror</label>
                    </div>
                </div>

                <div class="sf-checkout-section">
                    <h2>{{ __('storefront.shipping_method') }}</h2>
                    <div class="sf-checkout-options">
                        <label><input type="radio" name="shipping_method" value="courier" @checked(old('shipping_method', 'courier') === 'courier') required><span>{{ __('storefront.courier_free') }}</span></label>
                        <label><input type="radio" name="shipping_method" value="courier_cod" @checked(old('shipping_method') === 'courier_cod') required><span>{{ __('storefront.courier_cod_free') }}</span></label>
                        <label><input type="radio" name="shipping_method" value="pickup" @checked(old('shipping_method') === 'pickup') required><span>{{ __('storefront.pickup_free') }}</span></label>
                    </div>
                    @error('shipping_method')<small class="sf-checkout-error">{{ $message }}</small>@enderror
                </div>

                <div class="sf-checkout-section">
                    <h2>{{ __('storefront.payment_method') }}</h2>
                    <div class="sf-checkout-options">
                        <label><input type="radio" name="payment_method" value="payu" @checked(old('payment_method', 'payu') === 'payu') required><span>{{ __('storefront.payu_checkout') }}</span></label>
                        <label><input type="radio" name="payment_method" value="blik" @checked(old('payment_method') === 'blik') required><span>{{ __('storefront.blik_payu') }}</span></label>
                    </div>
                    @error('payment_method')<small class="sf-checkout-error">{{ $message }}</small>@enderror
                </div>
            </section>

            <section class="sf-checkout-card">
                <h2>{{ __('storefront.acceptances') }}</h2>
                <label class="sf-checkout-terms">
                    <input type="checkbox" name="terms" value="1" @checked(old('terms')) required>
                    <span>{!! __('storefront.accept_terms', ['terms' => '<a href="'.route('storefront.terms').'" target="_blank">'.__('storefront.terms').'</a>']) !!}</span>
                </label>
                @error('terms')<small class="sf-checkout-error">{{ $message }}</small>@enderror
            </section>
        </div>

        <aside class="sf-cart-summary sf-checkout-summary">
            <h2>{{ __('storefront.summary') }}</h2>
            <div class="sf-checkout-summary__items">
                @foreach($items as $item)
                    <div class="sf-checkout-summary__row sf-checkout-summary__row--product">
                        <span class="sf-checkout-summary__product-title">{{ $item['name'] }} <em>× {{ $item['quantity'] }}</em></span>
                        <strong>{{ number_format((float) $item['line_total'], 2, ',', ' ') }} {{ $item['currency'] }}</strong>
                    </div>
                @endforeach
            </div>
            <div class="sf-checkout-summary__row"><span>{{ __('storefront.delivery') }}</span><strong>0,00 {{ $items->first()['currency'] ?? 'PLN' }}</strong></div>
            <div class="sf-checkout-summary__row sf-checkout-summary__row--total"><span>{{ __('storefront.total') }}</span><strong>{{ number_format((float) $subtotal, 2, ',', ' ') }} {{ $items->first()['currency'] ?? 'PLN' }}</strong></div>
            <button class="sf-btn" type="submit">{{ __('storefront.place_order') }}</button>
        </aside>
    </form>
</div>
<script>
    (() => {
        const form = document.querySelector('.sf-checkout-layout');
        if (! form) return;
        const setGroupVisibility = (container, isVisible) => {
            if (! container) return;
            container.hidden = ! isVisible;
            container.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
            container.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = ! isVisible;
            });
        };

        const update = () => {
            const isCompany = form.querySelector('input[name="customer_type"]:checked')?.value === 'company';
            form.querySelectorAll('.sf-company-field').forEach((field) => setGroupVisibility(field, isCompany));

            const customShipping = form.querySelector('input[name="shipping_same_as_billing"]:checked')?.value === '0';
            setGroupVisibility(form.querySelector('.sf-shipping-fields'), customShipping);
        };
        form.addEventListener('change', (event) => {
            if (event.target.matches('input[name="customer_type"], input[name="shipping_same_as_billing"]')) update();
        });
        update();
    })();
</script>
@endsection

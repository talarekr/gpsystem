@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-cart-page">
    @include('storefront.partials.breadcrumbs')
    <div class="sf-cart-head"><h1>Zamówienie</h1><a class="sf-btn sf-btn--outline" href="{{ route('storefront.cart.index') }}">Wróć do koszyka</a></div>
    <form method="post" action="{{ route('storefront.checkout.store') }}" class="sf-cart-layout">
        @csrf
        <section class="sf-info-card">
            <h2>Dane klienta</h2>
            @if($errors->any())<div class="sf-alert sf-alert--error">Sprawdź poprawność formularza.</div>@endif
            <label>Imię i nazwisko / nazwa firmy<input name="customer_name" value="{{ old('customer_name') }}" required></label>
            <label>Nazwa firmy<input name="company_name" value="{{ old('company_name') }}"></label>
            <label>E-mail<input type="email" name="email" value="{{ old('email') }}" required></label>
            <label>Telefon<input name="phone" value="{{ old('phone') }}" required></label>
            <h2>Adres</h2>
            <label>Ulica i numer<input name="address_line1" value="{{ old('address_line1') }}" required></label>
            <label>Kod pocztowy<input name="postal_code" value="{{ old('postal_code') }}" required></label>
            <label>Miasto<input name="city" value="{{ old('city') }}" required></label>
            <label>Kraj<input name="country" value="{{ old('country', 'PL') }}" maxlength="2" required></label>
            <label>NIP<input name="nip" value="{{ old('nip') }}"></label>
            <label>Uwagi<textarea name="notes" rows="4">{{ old('notes') }}</textarea></label>
            <label><input type="checkbox" name="terms" value="1" @checked(old('terms')) required> Akceptuję <a href="{{ route('storefront.terms') }}" target="_blank">regulamin</a>.</label>
        </section>
        <aside class="sf-cart-summary">
            <h2>Podsumowanie</h2>
            @foreach($items as $item)
                <div><span>{{ $item['name'] }} × {{ $item['quantity'] }}</span><strong>{{ number_format((float) $item['line_total'], 2, ',', ' ') }} {{ $item['currency'] }}</strong></div>
            @endforeach
            <div><span>Dostawa</span><strong>0,00 {{ $items->first()['currency'] ?? 'PLN' }}</strong></div>
            <div><span>Razem</span><strong>{{ number_format((float) $subtotal, 2, ',', ' ') }} {{ $items->first()['currency'] ?? 'PLN' }}</strong></div>
            <button class="sf-btn" type="submit">Złóż zamówienie</button>
        </aside>
    </form>
</div>
@endsection

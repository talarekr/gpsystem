@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-static-page">
    @include('storefront.partials.breadcrumbs')

    <div class="sf-static-head">
        <p class="sf-eyebrow">Kontakt</p>
        <h1>Dane firmy</h1>
        <p>Skontaktuj się z nami w sprawie części samochodowych, doboru po VIN lub zamówienia.</p>
    </div>

    <div class="sf-contact-grid">
        <section class="sf-static-card sf-company-card">
            <h2>GREGOR swiss GRZEGORZ PACIOREK</h2>
            <p>Milanowska 137<br>08-460 Sobolew</p>
            <p><strong>NIP:</strong> 8262157853<br><strong>REGON:</strong> 368948917</p>
            <p><strong>Tel:</strong> <a href="tel:+48504266984">504 266 984</a><br><strong>E-mail:</strong> <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
        </section>

        <section class="sf-static-card">
            <h2>Formularz kontaktowy</h2>
            <form class="sf-contact-form" method="post" action="{{ route('storefront.contact.send') }}">
                @csrf
                <label>Imię i nazwisko
                    <input name="name" value="{{ old('name') }}" autocomplete="name">
                    @error('name')<span>{{ $message }}</span>@enderror
                </label>
                <label>Email <em>wymagane</em>
                    <input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                    @error('email')<span>{{ $message }}</span>@enderror
                </label>
                <label>Wiadomość
                    <textarea name="message" rows="6">{{ old('message') }}</textarea>
                    @error('message')<span>{{ $message }}</span>@enderror
                </label>
                <button class="sf-btn" type="submit">Wyślij wiadomość</button>
            </form>
        </section>
    </div>

    <section class="sf-static-card sf-map-card">
        <h2>Mapa dojazdu</h2>
        <iframe title="Mapa Google - Milanowska 137, 08-460 Sobolew" src="https://www.google.com/maps?q=Milanowska%20137%2C%2008-460%20Sobolew&output=embed" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </section>
</div>
@endsection

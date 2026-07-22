@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-static-page">
    @include('storefront.partials.breadcrumbs')

    <div class="sf-static-head">
        <p class="sf-eyebrow">{{ __('storefront.contact') }}</p>
        <h1>{{ __('storefront.company_details') }}</h1>
        <p>{{ __('storefront.contact_intro') }}</p>
    </div>

    <div class="sf-contact-grid">
        <section class="sf-static-card sf-company-card">
            <h2>GREGOR swiss GRZEGORZ PACIOREK</h2>
            <p>Milanowska 137<br>08-460 Sobolew</p>
            <p><strong>{{ __('storefront.tax_id') }}:</strong> 8262157853<br><strong>REGON:</strong> 368948917</p>
            <p><strong>Tel:</strong> <a href="tel:+48504266984">504 266 984</a><br><strong>E-mail:</strong> <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
        </section>

        <section class="sf-static-card">
            <h2>{{ __('storefront.contact_form') }}</h2>
            <form class="sf-contact-form" method="post" action="{{ route('storefront.contact.send') }}">
                @csrf
                <label>{{ __('storefront.contact_name') }}
                    <input name="name" value="{{ old('name') }}" autocomplete="name">
                    @error('name')<span>{{ $message }}</span>@enderror
                </label>
                <label>Email <em>{{ __('storefront.required') }}</em>
                    <input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                    @error('email')<span>{{ $message }}</span>@enderror
                </label>
                <label>{{ __('storefront.message') }}
                    <textarea name="message" rows="6">{{ old('message') }}</textarea>
                    @error('message')<span>{{ $message }}</span>@enderror
                </label>
                <button class="sf-btn" type="submit">{{ __('storefront.send_message') }}</button>
            </form>
        </section>
    </div>

    <section class="sf-static-card sf-map-card">
        <h2>{{ __('storefront.map') }}</h2>
        <iframe title="Mapa Google - Milanowska 137, 08-460 Sobolew" src="https://www.google.com/maps?q=Milanowska%20137%2C%2008-460%20Sobolew&output=embed" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </section>
</div>
@endsection

@extends('layouts.storefront')
@section('content')
<div class="sf-container sf-auth-page"><section class="sf-auth-card sf-auth-card--wide">
<h1>Utwórz konto</h1>@include('storefront.auth._google',['label'=>'Zarejestruj przez Google'])<div class="sf-auth-separator"><span>lub</span></div>
<p class="sf-account-type">● Konto klienta (osoba prywatna lub firma)</p>
<form method="post" action="{{ route('storefront.register.store') }}" class="sf-auth-form sf-auth-grid">@csrf
@foreach(['tax_id'=>'Numer NIP (opcjonalnie)','company_name'=>'Nazwa firmy (opcjonalnie)','first_name'=>'Imię','last_name'=>'Nazwisko','phone'=>'Numer telefonu','email'=>'Adres e-mail'] as $name=>$label)
<label>{{ $label }}<input type="{{ $name==='email'?'email':'text' }}" name="{{ $name }}" value="{{ old($name) }}" {{ in_array($name,['first_name','last_name','phone','email'])?'required':'' }}>@error($name)<small>{{ $message }}</small>@enderror</label>
@endforeach
<label>Hasło<input type="password" name="password" required>@error('password')<small>{{ $message }}</small>@enderror</label><label>Potwierdź hasło<input type="password" name="password_confirmation" required></label>
<label class="sf-auth-check sf-auth-check--full"><input type="checkbox" name="terms" value="1" required> Akceptuję <a href="{{ route('storefront.terms') }}" target="_blank" rel="noopener">Regulamin</a> oraz <a href="{{ route('storefront.privacy-policy') }}" target="_blank" rel="noopener">Politykę prywatności</a>@error('terms')<small>{{ $message }}</small>@enderror</label>
<button class="sf-btn" type="submit">Zarejestruj się</button></form><p class="sf-auth-switch">Masz już konto? <a href="{{ route('storefront.login') }}">Zaloguj się</a></p>
</section></div>
@endsection

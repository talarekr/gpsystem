@extends('layouts.storefront')
@section('content')
<div class="sf-container sf-auth-page"><section class="sf-auth-card">
<h1>Zaloguj się</h1>@include('storefront.auth._google',['label'=>'Zaloguj przez Google'])<div class="sf-auth-separator"><span>lub</span></div>
<form method="post" action="{{ route('storefront.login.store') }}" class="sf-auth-form">@csrf
<label>Adres e-mail<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">@error('email')<small>{{ $message }}</small>@enderror</label>
<label>Hasło<span class="sf-password-field"><input id="login-password" type="password" name="password" required autocomplete="current-password"><button type="button" onclick="const i=document.getElementById('login-password');i.type=i.type==='password'?'text':'password'">👁</button></span>@error('password')<small>{{ $message }}</small>@enderror</label>
<div class="sf-auth-row"><label class="sf-auth-check"><input type="checkbox" name="remember" value="1"> Zapamiętaj mnie</label><a href="{{ route('password.request') }}">Zapomniałeś hasła?</a></div>
<button class="sf-btn" type="submit">Zaloguj się</button></form><p class="sf-auth-switch">Nie masz konta? <a href="{{ route('storefront.register') }}">Zarejestruj się</a></p>
</section></div>
@endsection

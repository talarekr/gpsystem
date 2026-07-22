@extends('layouts.storefront')
@section('content')
<div class="sf-container sf-auth-page"><section class="sf-auth-card">
<h1>{{ __('storefront.login') }}</h1>@include('storefront.auth._google',['label'=>__('storefront.login_google')])<div class="sf-auth-separator"><span>{{ __('storefront.or') }}</span></div>
<form method="post" action="{{ route('storefront.login.store') }}" class="sf-auth-form">@csrf
<label>{{ __('storefront.email') }}<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">@error('email')<small>{{ $message }}</small>@enderror</label>
<label>{{ __('storefront.password') }}<span class="sf-password-field"><input id="login-password" type="password" name="password" required autocomplete="current-password"><button type="button" onclick="const i=document.getElementById('login-password');i.type=i.type==='password'?'text':'password'">👁</button></span>@error('password')<small>{{ $message }}</small>@enderror</label>
<div class="sf-auth-row"><label class="sf-auth-check"><input type="checkbox" name="remember" value="1"> {{ __('storefront.remember_me') }}</label><a href="{{ route('password.request') }}">{{ __('storefront.forgot_question') }}</a></div>
<button class="sf-btn" type="submit">{{ __('storefront.login') }}</button></form><p class="sf-auth-switch">{{ __('storefront.no_account') }} <a href="{{ route('storefront.register') }}">{{ __('storefront.register') }}</a></p>
</section></div>
@endsection

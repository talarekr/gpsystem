@extends('layouts.storefront')
@section('content')
<div class="sf-container sf-auth-page"><section class="sf-auth-card sf-auth-card--wide">
<h1>{{ __('storefront.create_account') }}</h1>@include('storefront.auth._google',['label'=>__('storefront.register_google')])<div class="sf-auth-separator"><span>{{ __('storefront.or') }}</span></div>
<p class="sf-account-type">{{ __('storefront.account_type') }}</p>
<form method="post" action="{{ route('storefront.register.store') }}" class="sf-auth-form sf-auth-grid">@csrf
@foreach(['tax_id'=>__('storefront.tax_id_optional'),'company_name'=>__('storefront.company_name_optional'),'first_name'=>__('storefront.first_name'),'last_name'=>__('storefront.last_name'),'phone'=>__('storefront.phone'),'email'=>__('storefront.email')] as $name=>$label)
<label>{{ $label }}<input type="{{ $name==='email'?'email':'text' }}" name="{{ $name }}" value="{{ old($name) }}" {{ in_array($name,['first_name','last_name','phone','email'])?'required':'' }}>@error($name)<small>{{ $message }}</small>@enderror</label>
@endforeach
<label>{{ __('storefront.password') }}<input type="password" name="password" required>@error('password')<small>{{ $message }}</small>@enderror</label><label>{{ __('storefront.confirm_password') }}<input type="password" name="password_confirmation" required></label>
<label class="sf-auth-check sf-auth-check--full"><input type="checkbox" name="terms" value="1" required> {!! __('storefront.accept_register_terms', ['terms' => '<a href="'.route('storefront.terms').'" target="_blank" rel="noopener">'.__('storefront.terms').'</a>', 'privacy' => '<a href="'.route('storefront.privacy-policy').'" target="_blank" rel="noopener">'.__('storefront.privacy_policy').'</a>']) !!}@error('terms')<small>{{ $message }}</small>@enderror</label>
<button class="sf-btn" type="submit">{{ __('storefront.register') }}</button></form><p class="sf-auth-switch">{{ __('storefront.already_account') }} <a href="{{ route('storefront.login') }}">{{ __('storefront.login') }}</a></p>
</section></div>
@endsection

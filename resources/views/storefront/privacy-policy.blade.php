@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-static-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-static-card">
        <h1>{{ __('storefront.privacy_policy') }}</h1>
        {!! __('storefront.privacy_body') !!}
    </section>
</div>
@endsection

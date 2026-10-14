@if(\App\Support\StorefrontContext::isFrench(request()) && ($jarekProducts ?? null) && $jarekProducts->count())
<section class="sf-section"><div class="sf-section__head"><h2>Boîtes de vitesses — Jarek</h2><a href="{{ route('storefront.jarek.index') }}">{{ __('storefront.show_all') }}</a></div>
<div class="sf-grid sf-grid--3">@foreach($jarekProducts as $jarek)@include('storefront.fr.jarek-card')@endforeach</div>
@if(method_exists($jarekProducts, 'links')){{ $jarekProducts->links() }}@endif
</section>
@endif

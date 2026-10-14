@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $categoryAncestors ??= $categoryTreeService->ancestors($category);
@endphp

<nav class="sf-breadcrumbs" aria-label="{{ \App\Support\StorefrontContext::isFrench(request()) ? __('storefront.breadcrumbs_label', [], 'fr') : 'Breadcrumb' }}">
    <a href="{{ route('storefront.home') }}">{{ __('storefront.home') }}</a><span>/</span>
    @foreach($categoryAncestors as $ancestor)
        <a href="{{ $categoryTreeService->url($ancestor) }}">{{ $ancestor->storefrontPublicNameForLocale($storefrontContentLocale) }}</a><span>/</span>
    @endforeach
    <span>{{ $category->storefrontPublicNameForLocale($storefrontContentLocale) }}</span>
</nav>


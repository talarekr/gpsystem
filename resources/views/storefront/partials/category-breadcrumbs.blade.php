@php
    $categoryTreeService ??= app(\App\Services\Storefront\CategoryTreeService::class);
    $categoryAncestors ??= $categoryTreeService->ancestors($category);
@endphp

<nav class="sf-breadcrumbs" aria-label="Breadcrumb">
    <a href="{{ route('storefront.home') }}">Strona główna</a><span>/</span>
    @foreach($categoryAncestors as $ancestor)
        <a href="{{ $categoryTreeService->url($ancestor) }}">{{ $ancestor->name }}</a><span>/</span>
    @endforeach
    <span>{{ $category->name }}</span>
</nav>

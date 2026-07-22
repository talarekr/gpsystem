<div data-diagnostic="catalog-simple">
    @forelse($parts as $part)
        <article>{{ $part->name ?? __('storefront.default_part_name') }}</article>
    @empty
        <p>{{ __('storefront.no_products') }}</p>
    @endforelse
</div>

@if ($paginator->hasPages())
    <nav aria-label="{{ __('storefront.pagination_label', [], 'fr') }}">
        <p>{{ __('storefront.pagination_summary', ['first' => $paginator->firstItem() ?? 0, 'last' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()], 'fr') }}</p>
        <div class="pagination">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" aria-label="{{ __('storefront.pagination_previous_page', [], 'fr') }}">{{ __('storefront.pagination_previous', [], 'fr') }}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('storefront.pagination_previous_page', [], 'fr') }}">{{ __('storefront.pagination_previous', [], 'fr') }}</a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true">{{ $element }}</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="{{ __('storefront.pagination_go_to_page', ['page' => $page], 'fr') }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('storefront.pagination_next_page', [], 'fr') }}">{{ __('storefront.pagination_next', [], 'fr') }}</a>
            @else
                <span aria-disabled="true" aria-label="{{ __('storefront.pagination_next_page', [], 'fr') }}">{{ __('storefront.pagination_next', [], 'fr') }}</span>
            @endif
        </div>
    </nav>
@endif

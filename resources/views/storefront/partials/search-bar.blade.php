<form class="sf-search" action="{{ route('storefront.catalog') }}" method="get">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('storefront.search_long_placeholder') }}">
    <button type="submit">{{ __('storefront.search') }}</button>
</form>

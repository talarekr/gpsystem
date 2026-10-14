@php
    $catalogUiLocale = \App\Support\StorefrontContext::isFrench(request()) ? 'fr' : 'pl';
@endphp
<aside class="sf-filters">
    <h3>{{ __('storefront.filter_title', [], $catalogUiLocale) }}</h3>
    <form method="get" action="{{ $filterAction ?? route('storefront.catalog') }}">
        @if(request()->filled('q'))
            <input type="hidden" name="q" value="{{ request('q') }}">
        @endif
        @if(request()->filled('sort'))
            <input type="hidden" name="sort" value="{{ request('sort') }}">
        @endif

        <label>{{ __('storefront.filter_manufacturer', [], $catalogUiLocale) }}
            <select name="producer">
                <option value="">{{ __('storefront.filter_all_manufacturers', [], $catalogUiLocale) }}</option>
                @foreach($producers ?? [] as $producer)
                    <option value="{{ $producer }}" @selected(request('producer') === $producer)>{{ $producer }}</option>
                @endforeach
            </select>
        </label>

        <label>{{ __('storefront.filter_model', [], $catalogUiLocale) }}
            <select name="model">
                <option value="">{{ __('storefront.filter_all_models', [], $catalogUiLocale) }}</option>
                @foreach($models ?? [] as $model)
                    <option value="{{ $model }}" @selected(request('model') === $model)>{{ $model }}</option>
                @endforeach
            </select>
        </label>

        <div class="sf-filter-row">
            <label>{{ __('storefront.price_from') }}<input type="number" name="price_from" value="{{ request('price_from', request('price_min')) }}" min="0" step="1"></label>
            <label>{{ __('storefront.price_to') }}<input type="number" name="price_to" value="{{ request('price_to', request('price_max')) }}" min="0" step="1"></label>
        </div>

        <label>{{ __('storefront.part_number') }}<input name="part_number" value="{{ request('part_number') }}" placeholder="8E0 953 521D"></label>
        <button class="sf-btn" type="submit">{{ __('storefront.filter_apply', [], $catalogUiLocale) }}</button>
        <a class="sf-clear" href="{{ $filterAction ?? route('storefront.catalog') }}">{{ __('storefront.clear_filters') }}</a>
    </form>
</aside>


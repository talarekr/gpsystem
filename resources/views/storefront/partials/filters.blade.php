<aside class="sf-filters">
    <h3>Filtry</h3>
    <form method="get" action="{{ $filterAction ?? route('storefront.catalog') }}">
        @if(request()->filled('q'))
            <input type="hidden" name="q" value="{{ request('q') }}">
        @endif
        @if(request()->filled('sort'))
            <input type="hidden" name="sort" value="{{ request('sort') }}">
        @endif

        <label>Producent
            <select name="producer">
                <option value="">Wszyscy</option>
                @foreach($producers ?? [] as $producer)
                    <option value="{{ $producer }}" @selected(request('producer') === $producer)>{{ $producer }}</option>
                @endforeach
            </select>
        </label>

        <label>Model
            <select name="model">
                <option value="">Wszystkie</option>
                @foreach($models ?? [] as $model)
                    <option value="{{ $model }}" @selected(request('model') === $model)>{{ $model }}</option>
                @endforeach
            </select>
        </label>

        <div class="sf-filter-row">
            <label>Cena od<input type="number" name="price_from" value="{{ request('price_from', request('price_min')) }}" min="0" step="1"></label>
            <label>Cena do<input type="number" name="price_to" value="{{ request('price_to', request('price_max')) }}" min="0" step="1"></label>
        </div>

        <label>Numer części<input name="part_number" value="{{ request('part_number') }}" placeholder="8E0 953 521D"></label>
        <button class="sf-btn" type="submit">Filtruj</button>
        <a class="sf-clear" href="{{ $filterAction ?? route('storefront.catalog') }}">Wyczyść filtry</a>
    </form>
</aside>

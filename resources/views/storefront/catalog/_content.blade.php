@php
    $parts ??= collect();
    $catalogUrl = \Illuminate\Support\Facades\Route::has('storefront.catalog') ? route('storefront.catalog') : url('/czesci');
    $resultCount = method_exists($parts, 'total') ? $parts->total() : (method_exists($parts, 'count') ? $parts->count() : 0);
    $sortableQuery = request()->except(['sort', 'page']);
@endphp

<div class="sf-container sf-page">
    <h1>Katalog części</h1>
    <p class="sf-empty">Wszystkie dostępne produkty w katalogu. Użyj wyszukiwarki, numeru części lub sortowania, aby zawęzić wyniki.</p>

    <form class="sf-filters" method="get" action="{{ $catalogUrl }}">
        <h3>Wyszukaj w katalogu</h3>
        <label>Fraza
            <input type="search" name="q" value="{{ request('q') }}" placeholder="np. Audi, silnik, skrzynia">
        </label>
        <label>Numer części
            <input name="part_number" value="{{ request('part_number') }}" placeholder="np. M156E">
        </label>
        <label>Sortowanie
            <select name="sort">
                <option value="">Sortuj domyślnie</option>
                <option value="price_asc" @selected(request('sort') === 'price_asc')>Cena rosnąco</option>
                <option value="price_desc" @selected(request('sort') === 'price_desc')>Cena malejąco</option>
                <option value="name" @selected(request('sort') === 'name')>Nazwa</option>
            </select>
        </label>
        <button class="sf-btn" type="submit">Szukaj</button>
        <a class="sf-clear" href="{{ $catalogUrl }}">Wyczyść</a>
    </form>

    <section>
        <div class="sf-toolbar">
            <span>{{ $resultCount }} wyników</span>
            <form method="get" action="{{ $catalogUrl }}">
                @foreach($sortableQuery as $key => $value)
                    @if(is_array($value))
                        @foreach($value as $item)
                            <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                        @endforeach
                    @elseif($value !== null && $value !== '')
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <select name="sort" onchange="this.form.submit()">
                    <option value="">Sortuj domyślnie</option>
                    <option value="price_asc" @selected(request('sort') === 'price_asc')>Cena rosnąco</option>
                    <option value="price_desc" @selected(request('sort') === 'price_desc')>Cena malejąco</option>
                    <option value="name" @selected(request('sort') === 'name')>Nazwa</option>
                </select>
            </form>
        </div>

        <div class="sf-grid sf-grid--3">
            @forelse($parts as $part)
                @include('storefront.partials.product-card', ['part' => $part])
            @empty
                <p class="sf-empty">Brak produktów dla wybranych kryteriów.</p>
            @endforelse
        </div>

        @if(method_exists($parts, 'links'))
            {{ $parts->withQueryString()->links() }}
        @endif
    </section>
</div>

@php
    $parts = $parts ?? collect();

    $catalogUrl = '/czesci';

    $resultCount = method_exists($parts, 'total')
        ? $parts->total()
        : (method_exists($parts, 'count') ? $parts->count() : 0);

    $currentSort = $_GET['sort'] ?? '';
    $currentQ = $_GET['q'] ?? '';
    $currentPartNumber = $_GET['part_number'] ?? '';

    $sortableQuery = $_GET;
    unset($sortableQuery['sort'], $sortableQuery['page'], $sortableQuery['token'], $sortableQuery['stage']);
@endphp

<div class="sf-container sf-page">
    <h1>Katalog części</h1>
    <p class="sf-empty">Wszystkie dostępne produkty w katalogu. Użyj wyszukiwarki, numeru części lub sortowania, aby zawęzić wyniki.</p>

    <form class="sf-filters" method="get" action="{{ $catalogUrl }}">
        <h3>Wyszukaj w katalogu</h3>

        <label>Fraza
            <input type="search" name="q" value="{{ $currentQ }}" placeholder="np. Audi, silnik, skrzynia">
        </label>

        <label>Numer części
            <input name="part_number" value="{{ $currentPartNumber }}" placeholder="np. M156E">
        </label>

        <label>Sortowanie
            <select name="sort">
                <option value="" {{ $currentSort === '' ? 'selected' : '' }}>Sortuj domyślnie</option>
                <option value="price_asc" {{ $currentSort === 'price_asc' ? 'selected' : '' }}>Cena rosnąco</option>
                <option value="price_desc" {{ $currentSort === 'price_desc' ? 'selected' : '' }}>Cena malejąco</option>
                <option value="name" {{ $currentSort === 'name' ? 'selected' : '' }}>Nazwa</option>
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
                    <option value="" {{ $currentSort === '' ? 'selected' : '' }}>Sortuj domyślnie</option>
                    <option value="price_asc" {{ $currentSort === 'price_asc' ? 'selected' : '' }}>Cena rosnąco</option>
                    <option value="price_desc" {{ $currentSort === 'price_desc' ? 'selected' : '' }}>Cena malejąco</option>
                    <option value="name" {{ $currentSort === 'name' ? 'selected' : '' }}>Nazwa</option>
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
            {!! method_exists($parts, 'withQueryString') ? $parts->withQueryString()->links() : $parts->links() !!}
        @endif
    </section>
</div>

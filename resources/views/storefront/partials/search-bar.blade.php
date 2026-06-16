<form class="sf-search" action="{{ route('storefront.search') }}" method="get">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Wyszukiwanie według nazwy części, numeru części, kategorii, modelu samochodu...">
    <button type="submit">Szukaj</button>
</form>

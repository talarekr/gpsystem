@php
    use App\Filament\Resources\PartResource;
    use App\Filament\Resources\OrderResource;
    use App\Models\Order;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\Schema;

    $partsIndexUrl = PartResource::getUrl('index');
    $ordersUrl = class_exists(OrderResource::class) ? OrderResource::getUrl('index') : '#';
    $ordersCount = 0;

    if (Schema::hasTable('orders')) {
        try {
            $ordersCount = Order::query()->where('status', 'new')->count();
        } catch (QueryException) {
            $ordersCount = 0;
        }
    }
    $adminLogoUrl = route('admin.brand.logo');
@endphp

<div class="gps-admin-topbar" data-gps-admin-topbar>
    <div class="gps-admin-topbar__left">
        <button class="gps-admin-topbar__hamburger" type="button" data-gps-sidebar-toggle aria-label="Zwiń lub rozwiń menu boczne" aria-expanded="true">
            <span></span><span></span><span></span>
        </button>

        <a class="gps-admin-topbar__brand" href="{{ url('/admin') }}" aria-label="GP Swiss">
            <span class="gps-admin-topbar__brand-mark">
                <img src="{{ $adminLogoUrl }}" alt="" loading="eager">
            </span>
            <span class="gps-admin-topbar__brand-name">GP Swiss</span>
        </a>
    </div>

    <div class="gps-admin-topbar__center">
        <div class="gps-admin-search" data-gps-part-search data-endpoint="{{ route('admin.search.parts') }}">
            <span class="gps-admin-search__icon" aria-hidden="true">⌕</span>
            <input class="gps-admin-search__input" type="search" placeholder="Szukaj części: SKU, OEM, numer, nazwa…" autocomplete="off" data-gps-part-search-input>
            <div class="gps-admin-search__dropdown" data-gps-part-search-results hidden></div>
        </div>

        <a class="gps-admin-topbar__parts-link" href="{{ $partsIndexUrl }}">Wszystkie części</a>
    </div>

    <div class="gps-admin-topbar__right">
        <a class="gps-admin-topbar__action" href="{{ $ordersUrl }}" title="Zamówienia">
            <span aria-hidden="true">📋</span><span>Zamówienia</span><strong>{{ $ordersCount }}</strong>
        </a>
    </div>
</div>

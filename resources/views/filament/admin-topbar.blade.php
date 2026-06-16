@php
    use App\Filament\Resources\PartResource;

    $partsIndexUrl = PartResource::getUrl('index');
    $ordersUrl = class_exists(\App\Filament\Pages\Orders::class) ? \App\Filament\Pages\Orders::getUrl() : '#';
    $messagesUrl = '#';
    $ordersCount = 0;
    $messagesCount = 0;
@endphp

<div class="gps-admin-topbar" data-gps-admin-topbar>
    <div class="gps-admin-topbar__left">
        <button class="gps-admin-topbar__hamburger" type="button" data-gps-sidebar-toggle aria-label="Zwiń lub rozwiń menu boczne" aria-expanded="true">
            <span></span><span></span><span></span>
        </button>

        <a class="gps-admin-topbar__brand" href="{{ url('/admin') }}" aria-label="GPS Product Hub">
            <span class="gps-admin-topbar__brand-mark">GPS</span>
            <span class="gps-admin-topbar__brand-name">GPS Product Hub</span>
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
        <a class="gps-admin-topbar__action" href="{{ $messagesUrl }}" title="Wiadomości">
            <span aria-hidden="true">✉️</span><span>Wiadomości</span><strong>{{ $messagesCount }}</strong>
        </a>
        <span class="gps-admin-topbar__user" title="{{ auth()->user()?->email }}">
            <span>{{ mb_substr(auth()->user()?->name ?: auth()->user()?->email ?: 'U', 0, 1) }}</span>
            <strong>{{ auth()->user()?->name ?: 'Konto' }}</strong>
        </span>
    </div>
</div>

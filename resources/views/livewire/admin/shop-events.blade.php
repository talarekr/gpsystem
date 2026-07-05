<section class="gps-shop-events" aria-labelledby="gps-shop-events-title">
    <div class="gps-shop-events__header">
        <div class="gps-shop-events__title-group">
            <div>
                <h2 id="gps-shop-events-title">Dziennik obsługi</h2>
            </div>
        </div>

    </div>

    <nav class="gps-shop-events__tabs" aria-label="Filtry dziennika obsługi">
        @foreach ($tabs as $tabKey => $tabLabel)
            @php
                $tabCount = $counts[$tabKey] ?? 0;
                $tabClass = 'gps-shop-events__tab' . ($activeTab === $tabKey ? ' is-active' : '');
                $tabCountClass = 'gps-shop-events__tab-count' . ($tabKey === 'requires_action' ? ' gps-shop-events__tab-count--action' : '');
            @endphp
            <a
                href="{{ request()->fullUrlWithQuery(['shop_event_tab' => $tabKey]) }}"
                class="{{ $tabClass }}"
                wire:click.prevent="setTab('{{ $tabKey }}')"
            >
                <span>{{ $tabLabel }}</span>
                @if ($tabCount > 0)
                    <span class="{{ $tabCountClass }}">{{ $tabCount }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    @if ($events->isEmpty())
        <div class="gps-shop-events__empty">Brak nowych zdarzeń dla obsługi sklepu.</div>
    @else
        <div class="gps-shop-events__list">
            @foreach ($events as $event)
                @php($itemClass = 'gps-shop-events__item gps-shop-events__item--' . ($event['severity'] ?: 'info'))
                <article class="{{ $itemClass }}">
                    <div class="gps-shop-events__line">
                        <span class="gps-shop-events__time">{{ $event['time'] }}</span>
                        <span class="gps-shop-events__source">@include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $event['channel']])</span>
                        <span class="gps-shop-events__reference">{{ $event['title'] }}@if ($event['extra']) <span class="gps-shop-events__extra">{{ $event['extra'] }}</span>@endif</span>
                        <span class="gps-shop-events__storage">Magazyn: {{ $event['storage'] }}</span>
                        <span class="gps-shop-events__amount">{{ $event['amount'] }}</span>
                    </div>
                    @if ($event['url'])
                        <a class="gps-shop-events__open" href="{{ $event['url'] }}">Otwórz</a>
                    @endif
                </article>
            @endforeach
        </div>

        @if ($showMore)
            <div class="gps-shop-events__more-wrap">
                <a class="gps-shop-events__more" href="{{ $moreUrl }}">Pokaż więcej</a>
            </div>
        @endif
    @endif
</section>

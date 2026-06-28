<section class="gps-shop-events" aria-labelledby="gps-shop-events-title">
    <div class="gps-shop-events__header">
        <div class="gps-shop-events__title-group">
            <span class="gps-shop-events__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.86 17.08a2.75 2.75 0 0 1-5.72 0M18 9.5a6 6 0 1 0-12 0c0 6-2 6.75-2 6.75h16s-2-.75-2-6.75Z" />
                </svg>
            </span>
            <div>
                <h2 id="gps-shop-events-title">Dziennik obsługi</h2>
            </div>
        </div>

        <label class="gps-shop-events__sound-toggle">
            <input type="checkbox" data-gps-shop-event-sound-toggle>
            <span>Dźwięk powiadomień</span>
        </label>
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
                        <span class="gps-shop-events__separator" aria-hidden="true">—</span>
                        <span class="gps-shop-events__source">@include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $event['channel']])</span>
                        <span class="gps-shop-events__separator" aria-hidden="true">—</span>
                        <span class="gps-shop-events__reference">{{ $event['title'] }}@if ($event['extra']) <span class="gps-shop-events__extra">{{ $event['extra'] }}</span>@endif</span>
                        <span class="gps-shop-events__separator" aria-hidden="true">—</span>
                        <span class="gps-shop-events__storage">Magazyn: {{ $event['storage'] }}</span>
                        <span class="gps-shop-events__separator" aria-hidden="true">—</span>
                        <span>{{ $event['amount'] }}</span>
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

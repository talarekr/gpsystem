<x-filament-panels::page>
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
                    <p>Najważniejsze zdarzenia wymagające uwagi obsługi sklepu.</p>
                </div>
            </div>

            <label class="gps-shop-events__sound-toggle">
                <input type="checkbox" data-gps-shop-event-sound-toggle>
                <span>Dźwięk powiadomień</span>
            </label>
        </div>

        <nav class="gps-shop-events__tabs" aria-label="Filtry dziennika obsługi">
            @foreach ($this->shopEventTabs() as $tabKey => $tabLabel)
                <a
                    href="{{ request()->fullUrlWithQuery(['shop_event_tab' => $tabKey]) }}"
                    @class(['gps-shop-events__tab', 'is-active' => $this->activeShopEventTab() === $tabKey])
                >
                    {{ $tabLabel }}
                </a>
            @endforeach
        </nav>

        @php($shopEvents = $this->shopEvents())

        @if ($shopEvents->isEmpty())
            <div class="gps-shop-events__empty">Brak nowych zdarzeń dla obsługi sklepu.</div>
        @else
            <div class="gps-shop-events__list">
                @foreach ($shopEvents as $event)
                    @php($eventUrl = $event->dashboardUrl())
                    <article class="gps-shop-events__item gps-shop-events__item--{{ $event->severity ?: 'info' }}">
                        <div class="gps-shop-events__time">{{ $event->occurredAtForHumans() }}</div>
                        <div class="gps-shop-events__body">
                            <div class="gps-shop-events__badges">
                                <span class="gps-shop-events__badge gps-shop-events__badge--source">{{ $event->sourceLabel() }}</span>
                                <span class="gps-shop-events__badge gps-shop-events__badge--type">{{ $event->typeLabel() }}</span>
                                @if ($event->requires_action)
                                    <span class="gps-shop-events__badge gps-shop-events__badge--action">Wymaga reakcji</span>
                                @endif
                            </div>
                            <h3>
                                @if ($eventUrl)
                                    <a href="{{ $eventUrl }}">{{ $event->title }}</a>
                                @else
                                    {{ $event->title }}
                                @endif
                            </h3>
                            @if ($event->description)
                                <p>{{ \Illuminate\Support\Str::limit($event->description, 180) }}</p>
                            @endif
                            <div class="gps-shop-events__meta">
                                @if ($event->customer_name)
                                    <span>Klient: <strong>{{ $event->customer_name }}</strong></span>
                                @endif
                                @if ($event->external_reference)
                                    <span>Nr ref.: <strong>{{ $event->external_reference }}</strong></span>
                                @endif
                            </div>
                        </div>
                        @if ($eventUrl)
                            <a class="gps-shop-events__open" href="{{ $eventUrl }}">Otwórz</a>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</x-filament-panels::page>

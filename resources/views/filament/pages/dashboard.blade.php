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

        @php($shopEventTabCounts = $this->shopEventTabCounts())

        <nav class="gps-shop-events__tabs" aria-label="Filtry dziennika obsługi">
            @foreach ($this->shopEventTabs() as $tabKey => $tabLabel)
                @php($tabCount = $shopEventTabCounts[$tabKey] ?? 0)
                <a
                    href="{{ request()->fullUrlWithQuery(['shop_event_tab' => $tabKey]) }}"
                    class="gps-shop-events__tab{{ $this->activeShopEventTab() === $tabKey ? ' is-active' : '' }}"
                >
                    <span>{{ $tabLabel }}</span>
                    @if ($tabCount > 0)
                        <span class="gps-shop-events__tab-count{{ $tabKey === 'requires_action' ? ' gps-shop-events__tab-count--action' : '' }}">{{ $tabCount }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        @php($shopEvents = $this->shopEvents())

        @if ($shopEvents->isEmpty())
            <div class="gps-shop-events__empty">Brak nowych zdarzeń dla obsługi sklepu.</div>
        @else
            <div class="gps-shop-events__list">
                @foreach ($shopEvents as $event)
                    @php
                        $eventUrl = $event->dashboardUrl();
                        $isOrderEvent = $event->event_type === 'order';
                        $sourceChannel = data_get($event->payload, 'source_channel');
                        $orderTotal = data_get($event->payload, 'order_total', '—');
                    @endphp
                    <article class="gps-shop-events__item gps-shop-events__item--{{ $event->severity ?: 'info' }}">
                        <div class="gps-shop-events__time">{{ $event->occurredAtForHumans() }}</div>
                        <div class="gps-shop-events__body">
                            @if ($isOrderEvent)
                                <div class="gps-shop-events__compact-row">
                                    <span class="gps-shop-events__source">
                                        @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $sourceChannel])
                                    </span>
                                    <strong class="gps-shop-events__reference">{{ $event->external_reference ?: '—' }}</strong>
                                    <span class="gps-shop-events__amount">{{ $orderTotal }}</span>
                                </div>
                            @else
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
                            @endif
                        </div>
                        @if ($eventUrl)
                            <a class="gps-shop-events__open" href="{{ $eventUrl }}">Otwórz</a>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
    <section class="gps-quick-actions" aria-label="Szybkie akcje obsługi">
        <div class="gps-quick-actions__grid">
            <button type="button" class="gps-quick-action" data-gps-local-sale-open>
                <span class="gps-quick-action__icon">↘</span>
                <strong>Sprzedaż lokalna</strong>
            </button>
            <a class="gps-quick-action" href="{{ $this->addPartUrl() }}">
                <span class="gps-quick-action__icon">＋</span>
                <strong>Dodaj część</strong>
            </a>
            <a class="gps-quick-action" href="{{ $this->ordersUrl() }}">
                <span class="gps-quick-action__icon">☷</span>
                <strong>Zamówienia</strong>
            </a>
        </div>
    </section>

    @php($salesAnalytics = $this->salesAnalytics())

    <section class="gps-sales-analytics" aria-labelledby="gps-sales-analytics-title">
        <div class="gps-sales-analytics__header">
            <div class="gps-sales-analytics__title">
                <h2 id="gps-sales-analytics-title">Analityka sprzedaży</h2>
            </div>

            <nav class="gps-sales-analytics__ranges" aria-label="Zakres analityki sprzedaży">
                @foreach ($this->salesRangeTabs() as $rangeKey => $rangeLabel)
                    <a
                        href="{{ request()->fullUrlWithQuery(['sales_range' => $rangeKey]) }}"
                        @class(['gps-sales-analytics__range', 'is-active' => $salesAnalytics['range']['key'] === $rangeKey])
                    >
                        {{ $rangeLabel }}
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="gps-sales-analytics__grid">
            <article class="gps-sales-card gps-sales-card--summary">
                <div class="gps-sales-card__heading">
                    <span>Podsumowanie</span>
                    <small>{{ $salesAnalytics['range']['starts_at']->format('d.m.Y') }}–{{ $salesAnalytics['range']['ends_at']->format('d.m.Y') }}</small>
                </div>

                <div class="gps-sales-metrics">
                    <div class="gps-sales-metric">
                        <span>Sprzedaż online</span>
                        <span class="gps-sales-metric__value">{{ $this->formatPln($salesAnalytics['summary']['online_revenue_pln']) }}</span>
                    </div>
                    <div class="gps-sales-metric">
                        <span>Zamówienia</span>
                        <span class="gps-sales-metric__value">{{ $salesAnalytics['summary']['online_orders_count'] }}</span>
                    </div>
                    <div class="gps-sales-metric">
                        <span>Sprzedaż lokalna</span>
                        <span class="gps-sales-metric__value">{{ $this->formatPln($salesAnalytics['summary']['local_sales_pln']) }}</span>
                        @php($localSalesCount = $salesAnalytics['summary']['local_sales_count'])
                        <small>{{ $localSalesCount }} {{ $localSalesCount === 1 ? 'sprzedaż' : 'sprzedaży' }}</small>
                    </div>
                    <div class="gps-sales-metric gps-sales-metric--total">
                        <span>Sprzedaż łącznie</span>
                        <span class="gps-sales-metric__value">{{ $this->formatPln($salesAnalytics['summary']['total_sales_pln']) }}</span>
                        <small>Online + lokalnie</small>
                    </div>
                </div>
            </article>

            <aside class="gps-sales-card gps-sales-card--channels" aria-label="Sprzedaż per kanał">
                <div class="gps-sales-card__heading">
                    <span>Kanały</span>
                </div>

                @php($channelPresentationOrder = ['allegro' => 0, 'ovoko' => 1, 'ebay' => 2, 'shop' => 3])

                <div class="gps-sales-channels">
                    @foreach (collect($salesAnalytics['channels'])->sortBy(fn ($channel) => $channelPresentationOrder[$channel['key']] ?? 99)->values() as $channel)
                        <article class="gps-sales-channel gps-sales-channel--{{ $channel['key'] }}">
                            <div class="gps-sales-channel__body">
                                <div class="gps-sales-channel__topline">
                                    <span class="gps-sales-channel__wordmark gps-sales-channel__wordmark--{{ $channel['key'] }}">
                                        @if ($channel['key'] === 'ebay')
                                            <span style="color:#0064D2">e</span><span style="color:#E53238">B</span><span style="color:#F5AF02">a</span><span style="color:#86B817">y</span>
                                        @elseif (in_array($channel['key'], ['allegro', 'ovoko'], true))
                                            @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $channel['key']])
                                        @else
                                            {{ $channel['label'] }}
                                        @endif
                                    </span>
                                    <span>{{ $channel['orders_count'] }} zam.</span>
                                </div>
                                @if ($channel['key'] === 'ebay')
                                    <p>{{ $this->formatEur($channel['sales_eur'] ?? 0) }}</p>
                                    <small>{{ $this->formatPln($channel['sales_pln']) }} @if (($channel['exchange_rate_unavailable'] ?? false) === true) · kurs niedostępny @endif</small>
                                @else
                                    <p>{{ $this->formatPln($channel['sales_pln']) }}</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </aside>
        </div>
    </section>


    <div class="gps-local-sale-modal" data-gps-local-sale-modal hidden>
        <div class="gps-local-sale-modal__backdrop" data-gps-local-sale-close></div>
        <div class="gps-local-sale-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gps-local-sale-title">
            <form data-gps-local-sale-form action="{{ route('admin.local-sales.store') }}" method="post">
                @csrf
                <div class="gps-local-sale-modal__header">
                    <div>
                        <h2 id="gps-local-sale-title">Sprzedaż lokalna</h2>
                        <p>Zdejmij część ze stanu po sprzedaży na miejscu w biurze.</p>
                    </div>
                    <button type="button" class="gps-local-sale-modal__x" data-gps-local-sale-close aria-label="Zamknij">×</button>
                </div>
                <div class="gps-local-sale-modal__body">
                    <div class="gps-local-sale-alert" data-gps-local-sale-alert hidden></div>
                    <input type="hidden" name="part_id" data-gps-local-sale-part-id>
                    <input type="hidden" name="quantity" value="1">
                    <label class="gps-local-sale-field gps-local-sale-search">
                        <span>Część / numer części</span>
                        <input type="search" data-gps-local-sale-search placeholder="Wpisz min. 3 znaki: SKU, nazwę, numer części, OEM..." autocomplete="off">
                        <div class="gps-local-sale-results" data-gps-local-sale-results hidden></div>
                        <div class="gps-local-sale-selected" data-gps-local-sale-selected hidden></div>
                    </label>
                    <label class="gps-local-sale-field">
                        <span>Kwota sprzedaży <em>PLN</em></span>
                        <input type="number" name="amount" data-gps-local-sale-amount min="0.01" step="0.01" required>
                    </label>
                    <label class="gps-local-sale-field">
                        <span>Forma płatności</span>
                        <select name="payment_method" required>
                            <option value="cash">gotówka</option>
                            <option value="bank_transfer">przelew</option>
                        </select>
                    </label>
                    <label class="gps-local-sale-field">
                        <span>Notatka opcjonalna</span>
                        <textarea name="notes" rows="3" placeholder="np. sprzedane klientowi na miejscu"></textarea>
                    </label>
                </div>
                <div class="gps-local-sale-modal__footer">
                    <button type="button" class="gps-local-sale-button gps-local-sale-button--ghost" data-gps-local-sale-close>Anuluj</button>
                    <button type="submit" class="gps-local-sale-button gps-local-sale-button--primary">Zapisz i zdejmij ze stanu</button>
                </div>
            </form>
        </div>
    </div>

</x-filament-panels::page>

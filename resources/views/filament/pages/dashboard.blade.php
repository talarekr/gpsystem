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

        @php
            $activeShopEventTab = $this->activeShopEventTab();
            $shopEventTabCounts = $this->shopEventTabCounts();
        @endphp

        <nav class="gps-shop-events__tabs" aria-label="Filtry dziennika obsługi">
            @foreach ($this->shopEventTabs() as $tabKey => $tabLabel)
                @php
                    $tabCount = $shopEventTabCounts[$tabKey] ?? 0;
                    $tabClass = 'gps-shop-events__tab' . ($activeShopEventTab === $tabKey ? ' is-active' : '');
                    $tabCountClass = 'gps-shop-events__tab-count' . ($tabKey === 'requires_action' ? ' gps-shop-events__tab-count--action' : '');
                @endphp
                <a
                    href="{{ request()->fullUrlWithQuery(['shop_event_tab' => $tabKey]) }}"
                    class="{{ $tabClass }}"
                >
                    <span>{{ $tabLabel }}</span>
                    @if ($tabCount > 0)
                        <span class="{{ $tabCountClass }}">{{ $tabCount }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        @php
            $shopEvents = $this->shopEvents();
            $showMoreShopEvents = in_array($activeShopEventTab, ['all', 'requires_action'], true)
                && (($shopEventTabCounts[$activeShopEventTab] ?? 0) > 6);
        @endphp

        @if ($shopEvents->isEmpty())
            <div class="gps-shop-events__empty">Brak nowych zdarzeń dla obsługi sklepu.</div>
        @else
            <div class="gps-shop-events__list">
                @foreach ($shopEvents as $event)
                    @php($itemClass = 'gps-shop-events__item gps-shop-events__item--' . ($event['severity'] ?: 'info'))
                    <article class="{{ $itemClass }}">
                        <div class="gps-shop-events__line">
                            <span class="gps-shop-events__time">{{ $event['time'] }}</span>
                            <span class="gps-shop-events__separator" aria-hidden="true">—</span>
                            <span class="gps-shop-events__source">@include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $event['channel']])</span>
                            <span class="gps-shop-events__separator" aria-hidden="true">—</span>
                            <span>Numer zamówienia: {{ $event['reference'] }}</span>
                            <span class="gps-shop-events__separator" aria-hidden="true">—</span>
                            <span>{{ $event['amount'] }}</span>
                        </div>
                        @if ($event['url'])
                            <a class="gps-shop-events__open" href="{{ $event['url'] }}">Otwórz</a>
                        @endif
                    </article>
                @endforeach
            </div>

            @if ($showMoreShopEvents)
                <div class="gps-shop-events__more-wrap">
                    <a class="gps-shop-events__more" href="{{ $this->newOrdersUrl() }}">Pokaż więcej</a>
                </div>
            @endif
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

    <livewire:admin.sales-analytics />

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

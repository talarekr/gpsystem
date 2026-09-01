@php($salesAnalytics = $this->salesAnalytics())

<section class="gps-sales-analytics" aria-labelledby="gps-sales-analytics-title">
    <div class="gps-sales-analytics__header">
        <div class="gps-sales-analytics__title">
            <h2 id="gps-sales-analytics-title">Analityka sprzedaży</h2>
        </div>

        <nav class="gps-sales-analytics__ranges" aria-label="Zakres analityki sprzedaży">
            @foreach ($this->salesRangeTabs() as $rangeKey => $rangeLabel)
                @php($rangeClasses = 'gps-sales-analytics__range' . ($salesAnalytics['range']['key'] === $rangeKey ? ' is-active' : ''))
                <a
                    href="{{ request()->fullUrlWithQuery(['sales_range' => $rangeKey]) }}"
                    class="{{ $rangeClasses }}"
                    wire:click.prevent="setRange('{{ $rangeKey }}')"
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
                </div>
                <div class="gps-sales-metric gps-sales-metric--total">
                    <span>Sprzedaż łącznie</span>
                    <span class="gps-sales-metric__value">{{ $this->formatPln($salesAnalytics['summary']['total_sales_pln']) }}</span>
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
                                    @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $channel['key']])
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
            @if (collect($salesAnalytics['channels'])->sum('unconverted_orders_count') > 0)
                <p role="alert"><strong>Uwaga:</strong> {{ collect($salesAnalytics['channels'])->sum('unconverted_orders_count') }} zamówień pominięto w sumie PLN z powodu braku kursu NBP. <a href="{{ route('admin.tools.sales-analytics.currency-conversion-diagnose') }}">Diagnostyka walut</a></p>
            @endif
        </aside>
    </div>
</section>
